"""Builds a sheep (from the pack's Deer) or a pig (from its Shiba Inu), keeping the
pack's skeleton and every animation.

  blender -b --disable-autoexec --factory-startup Deer.blend --python make_quad.py -- <out_dir> sheep
  blender -b --disable-autoexec --factory-startup ShibaInu.blend --python make_quad.py -- <out_dir> pig
"""
import math
import sys
from pathlib import Path

import bmesh
import bpy
from mathutils import Vector

args = sys.argv[sys.argv.index('--') + 1:]
OUT, KIND = Path(args[0]), args[1]
OUT.mkdir(parents=True, exist_ok=True)

arm = bpy.data.objects['AnimalArmature']
body = next(o for o in bpy.data.objects if o.type == 'MESH')
for m in list(body.modifiers):
    if m.type != 'ARMATURE':
        body.modifiers.remove(m)
# Bake any object offset into the vertices so the mesh sits where the bones expect it.
if body.location.length > 0:
    off = body.location.copy()
    for v in body.data.vertices:
        v.co += off
    body.location = (0, 0, 0)
body.name = body.data.name = KIND.capitalize()

def srgb(hexcode):
    h = hexcode.lstrip('#')
    out = []
    for i in (0, 2, 4):
        c = int(h[i:i + 2], 16) / 255
        out.append(c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4)
    return (*out, 1.0)

def material(name, hexcode, rough=0.9):
    mat = bpy.data.materials.get(name) or bpy.data.materials.new(name)
    mat.use_nodes = True
    nt = mat.node_tree
    nt.nodes.clear()
    out = nt.nodes.new('ShaderNodeOutputMaterial')
    bsdf = nt.nodes.new('ShaderNodeBsdfPrincipled')
    nt.links.new(bsdf.outputs['BSDF'], out.inputs['Surface'])
    bsdf.inputs['Base Color'].default_value = srgb(hexcode)
    bsdf.inputs['Roughness'].default_value = rough
    bsdf.inputs['Metallic'].default_value = 0.0
    mat.diffuse_color = srgb(hexcode)
    return mat

def gidx(name):
    g = body.vertex_groups.get(name)
    return g.index if g else None

def weight_in(v, names):
    ids = {gidx(n) for n in names} - {None}
    return sum(g.weight for g in v.groups if g.group in ids)

def push(names, amount):
    for v in body.data.vertices:
        w = weight_in(v, names)
        if w > 0.3:
            v.co += v.normal * amount * min(1.0, w)

def shrink_ears(factor):
    for side in ('L', 'R'):
        root = arm.data.bones[f'Ear1.{side}'].head_local
        names = [f'Ear{i}.{side}' for i in range(1, 5)]
        for v in body.data.vertices:
            if weight_in(v, names) > 0.5:
                v.co = root + (v.co - root) * factor

def head_points():
    return [v.co.copy() for v in body.data.vertices if weight_in(v, ['Head']) > 0.5]

def add_part(bm, mat_name, group='Head'):
    me = bpy.data.meshes.new('part')
    bm.to_mesh(me)
    bm.free()
    ob = bpy.data.objects.new('part', me)
    bpy.context.scene.collection.objects.link(ob)
    me.materials.append(bpy.data.materials[mat_name])
    vg = ob.vertex_groups.new(name=group)
    vg.add(list(range(len(me.vertices))), 1.0, 'REPLACE')
    return ob

parts = []
LEGS = [f'{p}{s}' for p in ('FrontLowerLeg.', 'BackLowerLeg.', 'IKFrontLeg.', 'IKBackLeg.', 'FF.', 'FFB.') for s in ('L', 'R')]

if KIND == 'sheep':
    material('Main', '#ece6da')        # wool
    material('Main_Light', '#f7f3ec')
    material('Main_Dark', '#2b2724')   # face and legs
    material('Hooves', '#1c1917')
    material('Muzzle', '#1c1917')
    material('Eye_Lighter', '#8a6a3a')
    material('Eye_Black', '#121212')
    material('Eye_White', '#e2e2e2')
    # Wool: a big puffy barrel, a thick neck and woolly tops to the legs.
    push(['Body', 'Back', 'Torso', 'Torso2', 'Torso3'], 0.32)
    push(['Neck1', 'Neck2'], 0.2)
    push(['FrontUpperLeg.L', 'FrontUpperLeg.R', 'BackLeg.L', 'BackLeg.R', 'BackUpperLeg.L', 'BackUpperLeg.R', 'FrontShoulder.L', 'FrontShoulder.R', 'BackShoulder.L', 'BackShoulder.R'], 0.14)
    push(['Tail1', 'Tail2', 'Tail3'], 0.08)
    shrink_ears(0.62)
    # Dark face, ears and lower legs; everything else stays wool.
    dark = bpy.data.materials['Main_Dark']
    if dark.name not in [m.name for m in body.data.materials if m]:
        body.data.materials.append(dark)
    names = [m.name if m else '' for m in body.data.materials]
    dark_i = names.index('Main_Dark')
    wool = {names.index(n) for n in ('Main', 'Main_Light', 'Muzzle') if n in names}
    face = ['Head'] + [f'Ear{i}.{s}' for i in range(1, 5) for s in ('L', 'R')]
    for p in body.data.polygons:
        if p.material_index not in wool:
            continue
        vs = [body.data.vertices[i] for i in p.vertices]
        if sum(weight_in(v, face) for v in vs) / len(vs) > 0.5 or sum(weight_in(v, LEGS) for v in vs) / len(vs) > 0.5:
            p.material_index = dark_i
    # No woolly cap: it pushed the shop hats off the head.

elif KIND == 'pig':
    material('Main', '#e9a3a0')        # pink skin
    material('Main_Light', '#f3c1bc')
    material('Black', '#dc9590')       # the Shiba's dark patches become darker pink
    material('Snout', '#d9817e')
    material('Eyes_Black', '#121212')
    material('Eyes_White', '#e6e6e6')
    material('Eyes_Pupil', '#2a1a10')
    # A round, heavy body and a thick neck.
    push(['Body', 'Back', 'Torso', 'Torso2', 'Torso3'], 0.38)
    push(['Neck1', 'Neck2'], 0.08)
    push(['FrontUpperLeg.L', 'FrontUpperLeg.R', 'BackUpperLeg.L', 'BackUpperLeg.R', 'BackLeg.L', 'BackLeg.R'], 0.14)
    push(['FrontLowerLeg.L', 'FrontLowerLeg.R', 'BackLowerLeg.L', 'BackLowerLeg.R'], 0.05)
    shrink_ears(0.6)
    # Ears tip forward and down a little.
    for side in ('L', 'R'):
        root = arm.data.bones[f'Ear1.{side}'].head_local
        names = [f'Ear{i}.{side}' for i in range(1, 5)]
        for v in body.data.vertices:
            if weight_in(v, names) > 0.5:
                d = v.co - root
                v.co = root + Vector((d.x * 1.1, d.y - d.z * 0.35, d.z * 0.7))
    # The snout: a short flat cylinder on the end of the muzzle.
    hp = head_points()
    tip = min(hp, key=lambda c: c.y)
    front = [c for c in hp if c.y < tip.y + 0.12]
    centre = sum(front, Vector()) / len(front)
    bm = bmesh.new()
    bmesh.ops.create_cone(bm, cap_ends=True, segments=8, radius1=0.2, radius2=0.19, depth=0.22)
    bmesh.ops.rotate(bm, verts=bm.verts, cent=(0, 0, 0), matrix=__import__('mathutils').Matrix.Rotation(math.radians(90), 3, 'X'))
    bmesh.ops.scale(bm, vec=(1.0, 1.0, 0.8), verts=bm.verts)
    bmesh.ops.translate(bm, vec=Vector((0, tip.y - 0.02, centre.z)), verts=bm.verts)
    parts.append(add_part(bm, 'Snout'))
    for sx in (1, -1):
        bm = bmesh.new()
        bmesh.ops.create_icosphere(bm, subdivisions=1, radius=0.035)
        bmesh.ops.translate(bm, vec=Vector((sx * 0.075, tip.y - 0.14, centre.z + 0.01)), verts=bm.verts)
        parts.append(add_part(bm, 'Eyes_Black'))

if parts:
    for ob in bpy.context.scene.objects:
        ob.select_set(False)
    for ob in parts + [body]:
        ob.select_set(True)
    bpy.context.view_layer.objects.active = body
    with bpy.context.temp_override(active_object=body, selected_editable_objects=parts + [body], selected_objects=parts + [body]):
        bpy.ops.object.join()

for old, new in (('Idle_HitReact_Left', 'Idle_HitReact1'), ('Idle_HitReact_Right', 'Idle_HitReact2')):
    if old in bpy.data.actions:
        bpy.data.actions[old].name = new
body.data.validate()
print(KIND, 'mesh', len(body.data.vertices), 'verts', [m.name for m in body.data.materials if m])
bpy.ops.wm.save_as_mainfile(filepath=str(OUT / f'{KIND.capitalize()}.blend'), copy=True)
print('saved', OUT / f'{KIND.capitalize()}.blend')
