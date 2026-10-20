"""Gives a "More animals" pack animal the first pack's eyes: white, black and pupil.

  blender -b --disable-autoexec --factory-startup Animal.blend --python add_eyes.py -- <eyes_from.blend> <out.blend> [size] [fx fy fz]

The eyes are taken from the first pack's Shiba Inu (Made in Blender/ShibaInu.blend),
scaled to the new animal's head, set on the head's surface either side, facing out
the way the old ones do, and skinned to the Head bone so they move with it.
"""
import sys

import bmesh
import bpy
from mathutils import Matrix, Vector

args = sys.argv[sys.argv.index('--') + 1:]
SOURCE, OUT = args[0], args[1]
SIZE = float(args[2]) if len(args) > 2 else 1.15
# Where on the head, as shares of its width, length (from the nose) and height.
FX, FY, FZ = (float(a) for a in args[3:6]) if len(args) > 5 else (0.32, 0.32, 0.66)

animal = next(o for o in bpy.data.objects if o.type == 'MESH')
arm = next(o for o in bpy.data.objects if o.type == 'ARMATURE')

# ---- the old eyes ---------------------------------------------------------------------
with bpy.data.libraries.load(SOURCE, link=False) as (src, dst):
    dst.objects = [n for n in src.objects if n == 'ShibaInu']
dog = dst.objects[0]
bm = bmesh.new()
bm.from_mesh(dog.data)
mats = dog.data.materials
eye_mats = {i for i, m in enumerate(mats) if m and m.name.startswith('Eyes_')}
# Head width, to scale by: the dog's main body at eye height.
head_w = 1.04
for f in [f for f in bm.faces if f.material_index not in eye_mats]:
    bm.faces.remove(f)
for v in [v for v in bm.verts if not v.link_faces]:
    bm.verts.remove(v)
# Its right eye (x > 0) only; the left is a mirror.
for f in [f for f in bm.faces if f.calc_center_median().x < 0]:
    bm.faces.remove(f)
for v in [v for v in bm.verts if not v.link_faces]:
    bm.verts.remove(v)
pts = [v.co.copy() for v in bm.verts]
centre = sum(pts, Vector()) / len(pts)
# Which way the old eye looks: its faces' normals, weighted by area.
facing = Vector()
for f in bm.faces:
    facing += f.normal * f.calc_area()
facing.normalize()
print('old eye centre', tuple(round(c, 3) for c in centre), 'facing', tuple(round(c, 2) for c in facing))

# ---- where the new head is ------------------------------------------------------------
group = animal.vertex_groups.get('Head')
head = []
for v in animal.data.vertices:
    for g in v.groups:
        if g.group == group.index and g.weight > 0.5:
            head.append(v.co.copy())
lo = Vector((min(p.x for p in head), min(p.y for p in head), min(p.z for p in head)))
hi = Vector((max(p.x for p in head), max(p.y for p in head), max(p.z for p in head)))
size = hi - lo
mid = (lo + hi) / 2
print('head', tuple(round(c, 2) for c in lo), tuple(round(c, 2) for c in hi))

# The old eye sits forward on the head, high, looking out to the side and ahead: aim there.
aim = Vector((facing.x * (1 + FX), facing.y, 0.2)).normalized()
target = Vector((mid.x + size.x * FX, lo.y + size.y * FY, lo.z + size.z * FZ))
deps = bpy.context.evaluated_depsgraph_get()
origin = target + aim * (size.length * 2)
hit, where, normal, _ = animal.ray_cast(origin, -aim, depsgraph=deps)
if not hit:
    where, normal = target, aim
print('eye at', tuple(round(c, 3) for c in where), 'normal', tuple(round(c, 2) for c in normal))

scale = size.x / head_w * SIZE
turn = facing.rotation_difference((normal + aim).normalized()).to_matrix().to_4x4()
place = Matrix.Translation(where) @ turn @ Matrix.Scale(scale, 4) @ Matrix.Translation(-centre)
bmesh.ops.transform(bm, matrix=place, verts=bm.verts)
# Out a little so it sits on the surface rather than in it.
bmesh.ops.translate(bm, vec=normal * scale * 0.02, verts=bm.verts)
left = bm.copy()
bmesh.ops.scale(left, vec=Vector((-1, 1, 1)), verts=left.verts)
bmesh.ops.translate(left, vec=Vector((mid.x * 2, 0, 0)), verts=left.verts)
bmesh.ops.reverse_faces(left, faces=left.faces)
# Join the eyes into the animal's own mesh, skinned wholly to the Head bone.
slots = {}
for i in eye_mats:
    m = mats[i]
    if m.name not in [x.name for x in animal.data.materials if x]:
        animal.data.materials.append(m)
    slots[i] = [x.name if x else None for x in animal.data.materials].index(m.name)
whole = bmesh.new()
whole.from_mesh(animal.data)
deform = whole.verts.layers.deform.verify()
for half in (bm, left):
    made = {}
    for v in half.verts:
        nv = whole.verts.new(v.co)
        nv[deform][group.index] = 1.0
        made[v] = nv
    for f in half.faces:
        nf = whole.faces.new([made[v] for v in f.verts])
        nf.material_index = slots.get(f.material_index, 0)
        nf.smooth = f.smooth
whole.to_mesh(animal.data)
animal.data.update()

# Clean, matte colours for the eyes too, as the pack's own materials.
for mat in animal.data.materials:
    if mat and mat.name.startswith('Eyes_') and mat.node_tree:
        bsdf = next((n for n in mat.node_tree.nodes if n.type == 'BSDF_PRINCIPLED'), None)
        if bsdf:
            bsdf.inputs['Metallic'].default_value = 0.0

bpy.ops.wm.save_as_mainfile(filepath=OUT, copy=True)
print('saved', OUT)
