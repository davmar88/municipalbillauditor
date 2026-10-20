"""Turns the tortoise download (a 50,000-face sculpt with textures, no rig) into a game animal.

  blender -b --disable-autoexec --factory-startup --python make_tortoise.py -- <tortoise.fbx> <out.blend> [nod_sign]

Low-poly and flat-coloured like the other animals, with a simple rig (Body, Neck, Head,
four legs, Tail; each vertex follows its nearest bone) and the moves the game plays:
Idle, Walk, Eating, Fighting and Building.
"""
import math
import sys

import bpy
from bpy_extras import anim_utils
from mathutils import Quaternion, Vector
from mathutils.geometry import intersect_point_line

args = sys.argv[sys.argv.index('--') + 1:]
SRC, OUT = args[0], args[1]
SIGN = float(args[2]) if len(args) > 2 else 1.0

bpy.ops.wm.read_factory_settings(use_empty=True)
bpy.ops.import_scene.fbx(filepath=SRC)
scene = bpy.context.scene

for ob in list(bpy.data.objects):
    ob.animation_data_clear()
    if ob.type != 'MESH':
        bpy.data.objects.remove(ob)
for a in list(bpy.data.actions):
    bpy.data.actions.remove(a)


def flat_material(name, colour):
    mat = bpy.data.materials.new(name)
    mat.use_nodes = True
    nt = mat.node_tree
    nt.nodes.clear()
    out = nt.nodes.new('ShaderNodeOutputMaterial')
    bsdf = nt.nodes.new('ShaderNodeBsdfPrincipled')
    nt.links.new(bsdf.outputs['BSDF'], out.inputs['Surface'])
    rgb = tuple(int(colour[i:i + 2], 16) / 255 for i in (1, 3, 5))
    # Base Color is linear: square to match the hex as seen.
    bsdf.inputs['Base Color'].default_value = (*(c ** 2.2 for c in rgb), 1)
    bsdf.inputs['Roughness'].default_value = 0.9
    bsdf.inputs['Metallic'].default_value = 0.0
    mat.diffuse_color = (*(c ** 2.2 for c in rgb), 1)
    return mat


colours = {
    'body': flat_material('Skin', '#8f8a5a'),
    'shell': flat_material('Shell', '#6b5333'),
    'eyes': flat_material('Eyes_Black', '#141210'),
}
ratios = {'body': 0.045, 'shell': 0.05, 'eyes': 0.25}

meshes = []
for ob in [o for o in bpy.data.objects if o.type == 'MESH']:
    with bpy.context.temp_override(object=ob, active_object=ob, selected_objects=[ob], selected_editable_objects=[ob]):
        bpy.ops.object.transform_apply(location=True, rotation=True, scale=True)
        mod = ob.modifiers.new('Low', 'DECIMATE')
        mod.ratio = ratios.get(ob.name, 0.05)
        bpy.ops.object.modifier_apply(modifier=mod.name)
    ob.data.materials.clear()
    ob.data.materials.append(colours.get(ob.name, colours['body']))
    for p in ob.data.polygons:
        p.use_smooth = False
    meshes.append(ob)
    print('mesh', ob.name, len(ob.data.polygons), 'faces')

# One mesh, as the other animals are.
body = next(o for o in meshes if o.name == 'body')
with bpy.context.temp_override(object=body, active_object=body, selected_objects=meshes, selected_editable_objects=meshes):
    bpy.ops.object.join()
body.name = 'Tortoise'

# ---- the rig ----------------------------------------------------------------------------
arm_data = bpy.data.armatures.new('TortoiseArmature')
arm = bpy.data.objects.new('TortoiseArmature', arm_data)
scene.collection.objects.link(arm)
BONES = {
    # name: (head, tail, parent)
    'Body': ((0, 0.25, 0.45), (0, -0.3, 0.45), None),
    'Neck': ((0, -0.6, 0.66), (0, -0.72, 0.88), 'Body'),
    'Head': ((0, -0.72, 0.88), (0, -0.92, 1.0), 'Neck'),
    'FrontLeg.L': ((0.3, -0.48, 0.36), (0.34, -0.52, 0.0), 'Body'),
    'FrontLeg.R': ((-0.3, -0.48, 0.36), (-0.34, -0.52, 0.0), 'Body'),
    'BackLeg.L': ((0.32, 0.32, 0.36), (0.36, 0.36, 0.0), 'Body'),
    'BackLeg.R': ((-0.32, 0.32, 0.36), (-0.36, 0.36, 0.0), 'Body'),
    'Tail': ((0, 0.55, 0.45), (0, 0.74, 0.34), 'Body'),
}
bpy.context.view_layer.objects.active = arm
arm.select_set(True)
bpy.ops.object.mode_set(mode='EDIT')
for name, (h, t, _) in BONES.items():
    b = arm_data.edit_bones.new(name)
    b.head, b.tail = Vector(h), Vector(t)
for name, (_, _, parent) in BONES.items():
    if parent:
        arm_data.edit_bones[name].parent = arm_data.edit_bones[parent]
bpy.ops.object.mode_set(mode='OBJECT')

# Each vertex follows its nearest bone; the shell rides the body.
groups = {name: body.vertex_groups.new(name=name) for name in BONES}
shell_mat = [i for i, m in enumerate(body.data.materials) if m.name == 'Shell']
eyes_mat = [i for i, m in enumerate(body.data.materials) if m.name == 'Eyes_Black']
in_shell = set()
in_eyes = set()
for p in body.data.polygons:
    if p.material_index in shell_mat:
        in_shell.update(p.vertices)
    if p.material_index in eyes_mat:
        in_eyes.update(p.vertices)


def distances(co):
    out = []
    for name, (h, t, _) in BONES.items():
        point, along = intersect_point_line(co, Vector(h), Vector(t))
        # Skin behind where a limb or the neck starts stays with the body, so it doesn't lift as a flap.
        behind = name != 'Body' and along < -0.05
        along = min(1.0, max(0.0, along))
        d = (co - (Vector(h) + (Vector(t) - Vector(h)) * along)).length
        if name == 'Body':
            d *= 1.6  # the body bone runs through the middle of the shell
        if behind:
            d += 1.0
        out.append((max(d, 1e-4), name))
    return sorted(out)


for v in body.data.vertices:
    if v.index in in_shell:
        groups['Body'].add([v.index], 1.0, 'REPLACE')
        continue
    if v.index in in_eyes:
        groups['Head'].add([v.index], 1.0, 'REPLACE')
        continue
    # The two nearest bones share it, the nearer far more: joints bend smoothly instead of tearing.
    (d1, b1), (d2, b2) = distances(v.co)[:2]
    w1 = d2 ** 4 / (d1 ** 4 + d2 ** 4)
    groups[b1].add([v.index], w1, 'REPLACE')
    if 1 - w1 > 0.05:
        groups[b2].add([v.index], 1 - w1, 'REPLACE')
body.parent = arm
mod = body.modifiers.new('Armature', 'ARMATURE')
mod.object = arm

# ---- the moves --------------------------------------------------------------------------
arm.animation_data_create()
for pb in arm.pose.bones:
    pb.rotation_mode = 'QUATERNION'


def action(name, frames, pose):
    """pose(t) -> {bone: (axis, degrees)}, keyed every other frame and looped."""
    act = bpy.data.actions.new(name)
    act.use_fake_user = True
    slot = act.slots.new('OBJECT', arm.name)
    bag = anim_utils.action_ensure_channelbag_for_slot(act, slot)
    for f in range(0, frames + 1, 2):
        turns = pose(f / frames)
        for bone in BONES:
            axis, deg = turns.get(bone, ('X', 0.0))
            q = Quaternion(Vector({'X': (1, 0, 0), 'Y': (0, 1, 0), 'Z': (0, 0, 1)}[axis]), math.radians(deg))
            path = f'pose.bones["{bone}"].rotation_quaternion'
            for i in range(4):
                fc = bag.fcurves.find(path, index=i) or bag.fcurves.new(path, index=i)
                fc.keyframe_points.insert(f, q[i], options={'FAST'})
    print('action', name, frames)
    return act


s = math.sin
TAU = 2 * math.pi
action('Idle', 80, lambda p: {'Head': ('X', 4 * s(p * TAU) * SIGN), 'Neck': ('X', 2 * s(p * TAU + 1) * SIGN), 'Tail': ('Z', 6 * s(p * TAU))})
action('Walk', 40, lambda p: {
    'FrontLeg.L': ('X', 22 * s(p * TAU)),
    'BackLeg.R': ('X', 22 * s(p * TAU)),
    'FrontLeg.R': ('X', -22 * s(p * TAU)),
    'BackLeg.L': ('X', -22 * s(p * TAU)),
    'Body': ('Y', 3 * s(p * TAU)),
    'Neck': ('Z', 4 * s(p * TAU)),
    'Tail': ('Z', 10 * s(p * TAU)),
})
action('Eating', 60, lambda p: {'Neck': ('X', 32 * SIGN), 'Head': ('X', (34 + 8 * s(p * TAU * 3)) * SIGN)})
action('Fighting', 36, lambda p: {
    # Draw back into the shell, then snap forward.
    'Neck': ('X', (-25 if p < 0.4 else 30 * s((p - 0.4) / 0.6 * math.pi)) * SIGN),
    'Head': ('X', (10 * s(p * TAU * 2)) * SIGN),
    'FrontLeg.L': ('X', 10 * s(p * TAU)),
    'FrontLeg.R': ('X', 10 * s(p * TAU)),
})


def blow(p):
    q = (p * 4) % 1.0
    return math.sin(q / 0.25 * math.pi / 2) if q < 0.25 else math.cos((q - 0.25) / 0.75 * math.pi / 2)


action('Building', 48, lambda p: {'Neck': ('X', (20 + 6 * blow(p)) * SIGN), 'Head': ('X', (6 + 26 * blow(p)) * SIGN)})

arm.animation_data.action = bpy.data.actions['Idle']
arm.animation_data.action_slot = bpy.data.actions['Idle'].slots[0]
bpy.ops.wm.save_as_mainfile(filepath=OUT)
print('saved', OUT, len(body.data.polygons), 'faces')
