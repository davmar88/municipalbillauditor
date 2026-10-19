"""Turns the pack's Deer into a goat that keeps the deer's skeleton and all 13 animations.

Run with the Deer.blend open:
  blender -b --disable-autoexec --factory-startup Deer.blend --python make_goat.py -- <out_dir> [coat]
"""
import math
import sys
from pathlib import Path

import bmesh
import bpy
from mathutils import Matrix, Vector

args = sys.argv[sys.argv.index('--') + 1:]
OUT = Path(args[0])
COAT = args[1] if len(args) > 1 else 'white'
OUT.mkdir(parents=True, exist_ok=True)

arm = bpy.data.objects['AnimalArmature']
body = bpy.data.objects['Deer']
body.name = 'Goat'
body.data.name = 'Goat'

# ---- coat -------------------------------------------------------------------
def srgb(hexcode):
    """Hex to the linear colour Blender's materials use."""
    h = hexcode.lstrip('#')
    out = []
    for i in (0, 2, 4):
        c = int(h[i:i + 2], 16) / 255
        out.append(c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4)
    return (*out, 1.0)

COATS = {
    'white': {'Main': '#e9e4da', 'Main_Light': '#f6f3ee', 'Main_Dark': '#b9b0a3', 'Muzzle': '#c9a9a0', 'Hooves': '#3a3330'},
    'brown': {'Main': '#7a5237', 'Main_Light': '#d9cbb8', 'Main_Dark': '#2e2a28', 'Muzzle': '#3a302b', 'Hooves': '#2a2522'},
}
COLOURS = {**COATS[COAT], 'Eye_Lighter': '#d7a42e', 'Eye_Black': '#141414', 'Eye_White': '#e6e6e6', 'Horns': '#b7a78a'}

def material(name):
    """A clean Principled material in the given colour.

    The pack's own node trees don't reach the glTF exporter (they export as plain
    metal), so each material gets a fresh, connected tree.
    """
    mat = bpy.data.materials.get(name) or bpy.data.materials.new(name)
    mat.use_nodes = True
    nt = mat.node_tree
    nt.nodes.clear()
    out = nt.nodes.new('ShaderNodeOutputMaterial')
    bsdf = nt.nodes.new('ShaderNodeBsdfPrincipled')
    nt.links.new(bsdf.outputs['BSDF'], out.inputs['Surface'])
    bsdf.inputs['Base Color'].default_value = srgb(COLOURS[name])
    bsdf.inputs['Roughness'].default_value = 0.9
    bsdf.inputs['Metallic'].default_value = 0.0
    mat.diffuse_color = srgb(COLOURS[name])
    mat.roughness = 0.9
    mat.metallic = 0.0
    return mat

for name in COLOURS:
    material(name)

# ---- where the head is ---------------------------------------------------------
head_group = body.vertex_groups['Head'].index
def head_verts():
    """Rest-pose positions (armature space) of vertices that move mostly with the head."""
    out = []
    for v in body.data.vertices:
        w = max((g.weight for g in v.groups if g.group == head_group), default=0)
        if w > 0.5:
            out.append(body.matrix_world @ v.co)
    return out

hv = head_verts()
ear_l = arm.data.bones['Ear1.L'].head_local
head_bone = arm.data.bones['Head']
print('head vertices', len(hv), 'z range', round(min(v.z for v in hv), 3), round(max(v.z for v in hv), 3), 'y range', round(min(v.y for v in hv), 3), round(max(v.y for v in hv), 3))

def top_of_skull(x, y, radius=0.09):
    near = [v.z for v in hv if abs(v.x - x) < radius and abs(v.y - y) < radius]
    return max(near) if near else head_bone.head_local.z

# ---- horns ----------------------------------------------------------------------
def horn(side):
    """A tapered horn sweeping up, then back and a little down, from just in front of the ear."""
    bm = bmesh.new()
    sides, segments = 6, 8
    base = Vector((0.1 * side, ear_l.y - 0.3, 0))
    base.z = top_of_skull(base.x, base.y) - 0.03
    length, r0, r1 = 1.05, 0.09, 0.014
    step = length / segments
    pts, dirs = [base.copy()], []
    for i in range(segments):
        # Angle from straight up towards the goat's back (+Y).
        th = math.radians(8 + 112 * (i + 0.5) / segments)
        d = Vector((0.14 * side, math.sin(th), math.cos(th))).normalized()
        dirs.append(d)
        pts.append(pts[-1] + d * step)
    dirs.append(dirs[-1])
    rings = []
    for i, (c, d) in enumerate(zip(pts, dirs)):
        a = d.cross(Vector((1, 0, 0)))
        if a.length < 1e-6:
            a = Vector((0, 1, 0))
        a.normalize()
        b = d.cross(a).normalized()
        r = r0 + (r1 - r0) * i / segments
        rings.append([bm.verts.new(c + (a * math.cos(2 * math.pi * k / sides) + b * math.sin(2 * math.pi * k / sides)) * r) for k in range(sides)])
    for ra, rb in zip(rings, rings[1:]):
        for k in range(sides):
            bm.faces.new((ra[k], ra[(k + 1) % sides], rb[(k + 1) % sides], rb[k]))
    bm.faces.new(list(reversed(rings[0])))
    tip = bm.verts.new(pts[-1] + dirs[-1] * 0.04)
    for k in range(sides):
        bm.faces.new((rings[-1][k], rings[-1][(k + 1) % sides], tip))
    bmesh.ops.recalc_face_normals(bm, faces=bm.faces)
    me = bpy.data.meshes.new(f'Horn{side}')
    bm.to_mesh(me)
    bm.free()
    ob = bpy.data.objects.new(f'Horn{side}', me)
    bpy.context.scene.collection.objects.link(ob)
    me.materials.append(bpy.data.materials['Horns'])
    return ob

# ---- beard ----------------------------------------------------------------------
def beard():
    front = [v for v in hv if v.y < head_bone.head_local.y - 0.45]
    chin = min(front, key=lambda v: v.z)
    bm = bmesh.new()
    bmesh.ops.create_cone(bm, cap_ends=True, segments=6, radius1=0.02, radius2=0.1, depth=0.42)
    me = bpy.data.meshes.new('Beard')
    bm.to_mesh(me)
    bm.free()
    ob = bpy.data.objects.new('Beard', me)
    bpy.context.scene.collection.objects.link(ob)
    # Point down from under the chin, a little behind the mouth.
    ob.location = Vector((0, chin.y + 0.2, chin.z - 0.14))
    ob.rotation_euler = (math.radians(-12), 0, 0)
    me.materials.append(bpy.data.materials['Main_Light'])
    return ob

parts = [horn(1), horn(-1), beard()]

# Every new part moves with the head.
for ob in parts:
    vg = ob.vertex_groups.new(name='Head')
    vg.add(list(range(len(ob.data.vertices))), 1.0, 'REPLACE')

for ob in bpy.context.scene.objects:
    ob.select_set(False)
for ob in parts:
    ob.select_set(True)
body.select_set(True)
bpy.context.view_layer.objects.active = body
with bpy.context.temp_override(active_object=body, selected_editable_objects=parts + [body], selected_objects=parts + [body]):
    bpy.ops.object.join()
print('goat mesh', len(body.data.vertices), 'verts', len(body.data.polygons), 'faces', [m.name for m in body.data.materials])

# Smaller, neater ears than the deer's.
for side in ('L', 'R'):
    groups = {body.vertex_groups[f'Ear{i}.{side}'].index for i in range(1, 5)}
    root = arm.data.bones[f'Ear1.{side}'].head_local
    for v in body.data.vertices:
        w = sum(g.weight for g in v.groups if g.group in groups)
        if w > 0.5:
            v.co = root + (v.co - root) * 0.72

# A stockier body than the deer's: push the barrel out a little along its normals.
barrel = {body.vertex_groups[n].index for n in ('Body', 'Back', 'Torso', 'Torso2', 'Torso3')}
for v in body.data.vertices:
    w = sum(g.weight for g in v.groups if g.group in barrel)
    if w > 0.5:
        v.co += v.normal * 0.09 * w

# The pack's glTF files name the two hit reactions 1 and 2.
for old, new in (('Idle_HitReact_Left', 'Idle_HitReact1'), ('Idle_HitReact_Right', 'Idle_HitReact2')):
    if old in bpy.data.actions:
        bpy.data.actions[old].name = new

# The deer's weights run a hair over 1.0 in places; validate clamps them.
body.data.validate()

bpy.ops.wm.save_as_mainfile(filepath=str(OUT / f'Goat_{COAT}.blend'), copy=True)
print('saved', OUT / f'Goat_{COAT}.blend')
