"""Turns one of the Cats pack's cats into a game animal, in one of the pack's 12 coats.

  blender -b --disable-autoexec --factory-startup --python make_cat.py -- <cat.fbx> <coat.png> <out.glb> [out.blend] [base_frame]

The pack's cats carry one long take of poses and no walk. This keeps the start of the
take as Idle and builds Walk, Eating, Fighting and Building on the cat's own rig. Which
way each bone turns is found by trying it: a leg swings the way that moves its paw
forward, a neck nods the way that lowers the head.
"""
import math
import sys

import bpy
from bpy_extras import anim_utils
from mathutils import Quaternion, Vector

args = sys.argv[sys.argv.index('--') + 1:]
SRC, COAT, OUT = args[0], args[1], args[2]
BLEND = args[3] if len(args) > 3 else None
# The take frame to build on, when the pick below won't do (House_cat 440, Modn_cat 52 look best).
FORCE = int(args[4]) if len(args) > 4 else None

bpy.ops.wm.read_factory_settings(use_empty=True)
bpy.ops.import_scene.fbx(filepath=SRC)
scene = bpy.context.scene

# Keep only the cat and its rig, each where it stands in the world: some cats hang under empty groups.
keep = [o for o in bpy.data.objects if o.type in ('MESH', 'ARMATURE')]
placed = {o.name: o.matrix_world.copy() for o in keep}
for ob in keep:
    if ob.parent is not None and ob.parent.type not in ('MESH', 'ARMATURE'):
        ob.parent = None
        ob.matrix_world = placed[ob.name]
for ob in list(bpy.data.objects):
    if ob.type not in ('MESH', 'ARMATURE'):
        bpy.data.objects.remove(ob)
arm = next(o for o in bpy.data.objects if o.type == 'ARMATURE')
arm.name = 'CatArmature'
take = arm.animation_data.action
take.name = 'Take'

# Plain names, as the game's other animals have: the pack prefixes every bone with its scene.
NAMES = {'Root_M': 'Body', 'Chest_M': 'Torso', 'Neck_M': 'Neck', 'Head_M': 'Head'}
for bone in arm.data.bones:
    plain = bone.name.split(':')[-1]
    bone.name = NAMES.get(plain, plain)

# The coat: the pack's texture for this colour, made smaller so the model loads quickly.
image = bpy.data.images.load(COAT)
image.scale(512, 512)
image.pack()
for ob in [o for o in bpy.data.objects if o.type == 'MESH']:
    for mat in ob.data.materials:
        if not mat:
            continue
        mat.use_nodes = True
        nt = mat.node_tree
        nt.nodes.clear()
        out = nt.nodes.new('ShaderNodeOutputMaterial')
        bsdf = nt.nodes.new('ShaderNodeBsdfPrincipled')
        tex = nt.nodes.new('ShaderNodeTexImage')
        tex.image = image
        nt.links.new(tex.outputs['Color'], bsdf.inputs['Base Color'])
        nt.links.new(bsdf.outputs['BSDF'], out.inputs['Surface'])
        bsdf.inputs['Roughness'].default_value = 0.9
        bsdf.inputs['Metallic'].default_value = 0.0
        mat.name = 'Coat'

pose = arm.pose.bones
for pb in pose:
    pb.rotation_mode = 'QUATERNION'
take_bag = anim_utils.action_get_channelbag_for_slot(take, take.slots[0])
take_curves = {(f.data_path, f.array_index): f for f in take_bag.fcurves}
SLOT = take.slots[0].name_display


def bone_of(path):
    return path.split('"')[1]


def at(bone_name, frame):
    """The take's rotation of a bone at a frame, as a quaternion."""
    return Quaternion([
        take_curves[(f'pose.bones["{bone_name}"].rotation_quaternion', i)].evaluate(frame)
        if (f'pose.bones["{bone_name}"].rotation_quaternion', i) in take_curves else (1, 0, 0, 0)[i]
        for i in range(4)
    ])


def world(name):
    return arm.matrix_world @ pose[name].head


def settle(frame):
    arm.animation_data.action = take
    scene.frame_set(frame)
    bpy.context.view_layer.update()


def turning(bone, tip, want, degrees=25):
    """Which axis and sign turns this bone so its tip moves most the wanted way."""
    best = (('X', 1.0), -2.0)
    settle(BASE)
    rest = pose[bone].rotation_quaternion.copy()
    start = world(tip)
    for axis, v in (('X', (1, 0, 0)), ('Y', (0, 1, 0)), ('Z', (0, 0, 1))):
        for sign in (1.0, -1.0):
            arm.animation_data.action = None
            for pb in pose:
                pb.rotation_quaternion = at(pb.name, BASE)
            pose[bone].rotation_quaternion = rest @ Quaternion(Vector(v), math.radians(degrees * sign))
            bpy.context.view_layer.update()
            moved = world(tip) - start
            score = moved.normalized().dot(Vector(want)) if moved.length > 1e-9 else -2
            if score > best[1]:
                best = ((axis, sign), score)
    print('turn', bone, '->', best)
    return best[0]


def first(*names):
    return next(n for n in names if n in pose)


neck, head = 'Neck', 'Head'
tip = first('JawEnd_M', 'Jaw_M', 'Hair_M')

# The pose to build on: a moment in the take where the cat stands tall and looks straight ahead.
BASE, best = 2, 1e9
lo, hi = (int(x) for x in take.frame_range)
heights = {}
for f in range(lo, hi + 1, 2):
    settle(f)
    heights[f] = world('Body').z
tallest = max(heights.values())
for f, height in heights.items():
    # Only where it stands up on its legs, not lying down or crouched.
    if height < tallest * 0.85:
        continue
    settle(f)
    look = world(tip) - world(head)
    if look.length < 1e-9:
        continue
    look.normalize()
    # Straight ahead is -Y.
    score = abs(look.x) * 4 + max(0.0, look.y + 0.6) - height / tallest
    if score < best:
        BASE, best = f, score
if FORCE is not None:
    BASE = FORCE
print('base frame', BASE)
legs = {
    'front_L': ('frontHip_L', 'frontAnkle_L'),
    'front_R': ('frontHip_R', 'frontAnkle_R'),
    'back_L': ('backHip_L', 'backAnkle_L'),
    'back_R': ('backHip_R', 'backAnkle_R'),
}
# The cat faces -Y, as the pack's others: forward is -Y.
NOD = {b: turning(b, tip, (0, -0.3, -1)) for b in (neck, head)}
SWING = {k: turning(hip, ankle, (0, -1, 0.15)) for k, (hip, ankle) in legs.items()}
tail = first('Tail1_M', 'Tail0_M')
WAG = turning(tail, first('Tail4_M', 'Tail3_M'), (1, 0, 0))


def new_action(name):
    if name in bpy.data.actions:
        bpy.data.actions.remove(bpy.data.actions[name])
    act = bpy.data.actions.new(name)
    act.use_fake_user = True
    act.slots.new('OBJECT', SLOT)
    return act


def key(act, path, idx, frame, value):
    bag = anim_utils.action_ensure_channelbag_for_slot(act, act.slots[0])
    f = bag.fcurves.find(path, index=idx) or bag.fcurves.new(path, index=idx)
    f.keyframe_points.insert(frame, value, options={'FAST'})


def build(name, frames, turns_of, base=lambda p: BASE):
    """Every bone from the take at base(p), plus turns_of(p): {bone: [(axis, sign), degrees]}."""
    act = new_action(name)
    for f in range(0, frames + 1, 2):
        p = f / frames
        t = base(p)
        turns = turns_of(p)
        for (path, idx), fc in take_curves.items():
            if path.endswith('rotation_quaternion') and bone_of(path) in turns:
                continue
            key(act, path, idx, f, fc.evaluate(t))
        for bone, ((axis, sign), deg) in turns.items():
            v = {'X': (1, 0, 0), 'Y': (0, 1, 0), 'Z': (0, 0, 1)}[axis]
            q = at(bone, t) @ Quaternion(Vector(v), math.radians(deg * sign))
            for i in range(4):
                key(act, f'pose.bones["{bone}"].rotation_quaternion', i, f, q[i])
    print('action', name, frames)


s = math.sin
TAU = 2 * math.pi
# Idle: standing, looking about a little, tail swaying.
build('Idle', 96, lambda p: {tail: (WAG, 12 * s(p * TAU)), head: (NOD[head], 4 * s(p * TAU * 2)), neck: (NOD[neck], 2 * s(p * TAU))})
build('Walk', 32, lambda p: {
    legs['front_L'][0]: (SWING['front_L'], 22 * s(p * TAU)),
    legs['back_R'][0]: (SWING['back_R'], 22 * s(p * TAU)),
    legs['front_R'][0]: (SWING['front_R'], -22 * s(p * TAU)),
    legs['back_L'][0]: (SWING['back_L'], -22 * s(p * TAU)),
    tail: (WAG, 10 * s(p * TAU)),
    head: (NOD[head], 3 * s(p * TAU * 2)),
})
build('Eating', 60, lambda p: {neck: (NOD[neck], 34), head: (NOD[head], 22 + 7 * s(p * TAU * 3)), tail: (WAG, 8 * s(p * TAU))})
build('Fighting', 30, lambda p: {
    # A swipe of the front paw and a lunge of the head.
    legs['front_R'][0]: (SWING['front_R'], 55 * max(0.0, s(p * TAU))),
    head: (NOD[head], -10 * s(p * TAU)),
    neck: (NOD[neck], -6 * s(p * TAU)),
    tail: (WAG, 20 * s(p * TAU * 2)),
})


def blow(p):
    q = (p * 4) % 1.0
    return math.sin(q / 0.25 * math.pi / 2) if q < 0.25 else math.cos((q - 0.25) / 0.75 * math.pi / 2)


build('Building', 48, lambda p: {neck: (NOD[neck], 18 + 5 * blow(p)), head: (NOD[head], 6 + 24 * blow(p))})

# The long take isn't needed in the game.
bpy.data.actions.remove(take)
arm.animation_data.action = None

if BLEND:
    bpy.ops.wm.save_as_mainfile(filepath=BLEND)

bpy.ops.export_scene.gltf(
    filepath=OUT,
    export_format='GLB',
    export_animations=True,
    export_animation_mode='ACTIONS',
    export_skins=True,
    export_yup=True,
    export_texcoords=True,
    export_image_format='JPEG',
    export_force_sampling=True,
    export_reset_pose_bones=True,
)
print('exported', OUT)
