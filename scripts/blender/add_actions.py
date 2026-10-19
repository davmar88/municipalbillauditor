"""Adds Fighting and Building to a pack-rig animal, built from its own moves.

  blender -b --disable-autoexec --factory-startup Animal.blend --python add_actions.py -- <out.blend> [nod_sign]

Fighting: its attacks one after another, looping.
Building: its head-low idle with the head knocking down like a hammer and the body
bobbing with each blow.
"""
import math
import sys

import bpy
from bpy_extras import anim_utils
from mathutils import Quaternion, Vector

args = sys.argv[sys.argv.index('--') + 1:]
OUT = args[0]
# The pack's neck and head bones nod down for negative turns about their X axis.
SIGN = float(args[1]) if len(args) > 1 else -1.0

arm = bpy.data.objects['AnimalArmature']
acts = bpy.data.actions

# Plain metal on export otherwise: give every material a clean, connected tree in its own colour.
for mat in bpy.data.materials:
    colour = tuple(mat.diffuse_color)
    if mat.node_tree:
        bsdf = next((n for n in mat.node_tree.nodes if n.type == 'BSDF_PRINCIPLED'), None)
        if bsdf:
            colour = tuple(bsdf.inputs['Base Color'].default_value)
    mat.use_nodes = True
    nt = mat.node_tree
    nt.nodes.clear()
    o = nt.nodes.new('ShaderNodeOutputMaterial')
    b = nt.nodes.new('ShaderNodeBsdfPrincipled')
    nt.links.new(b.outputs['BSDF'], o.inputs['Surface'])
    b.inputs['Base Color'].default_value = colour
    b.inputs['Roughness'].default_value = 0.9
    b.inputs['Metallic'].default_value = 0.0

for old, new in (('Idle_HitReact_Left', 'Idle_HitReact1'), ('Idle_HitReact_Right', 'Idle_HitReact2')):
    if old in acts:
        acts[old].name = new

def bag(action):
    if not action.slots:
        action.slots.new('OBJECT', 'AnimalArmature')
    return anim_utils.action_ensure_channelbag_for_slot(action, action.slots[0])

def curves(action):
    return {(f.data_path, f.array_index): f for f in bag(action).fcurves}

def new_action(name):
    if name in acts:
        acts.remove(acts[name])
    a = acts.new(name)
    a.use_fake_user = True
    a.slots.new('OBJECT', 'AnimalArmature')
    return a

def put(dst, key, frame, value):
    cb = bag(dst)
    f = cb.fcurves.find(key[0], index=key[1]) or cb.fcurves.new(key[0], index=key[1])
    f.keyframe_points.insert(frame, value, options={'FAST'})

def length(action):
    return int(round(action.frame_range[1]))

# ---- Fighting ------------------------------------------------------------------------
moves = [m for m in ('Attack_Headbutt', 'Attack_Kick') if m in acts] or ['Attack', 'Attack']
fight = new_action('Fighting')
offset = 0
for name in moves:
    src = acts[name]
    n = length(src)
    for key, f in curves(src).items():
        for t in range(0, n + 1, 2):
            put(fight, key, offset + t, f.evaluate(t))
    offset += n
# Close the loop on the first move's opening pose.
first = acts[moves[0]]
for key, f in curves(first).items():
    put(fight, key, offset + 4, f.evaluate(0))
print('Fighting', offset + 4, 'frames from', moves)

# ---- Building ------------------------------------------------------------------------
base_name = next(m for m in ('Idle_Headlow', 'Idle_2_HeadLow', 'Eating', 'Idle') if m in acts)
base = acts[base_name]
build = new_action('Building')
N = 48              # four blows a loop
blows = 4
src = curves(base)
# Degrees of each blow, and how far each bone leans down before the blows start.
nod_bones = {'Head': 22, 'Neck3': 9, 'Neck2': 4}
lean = {'Head': 4, 'Neck3': 8, 'Neck2': 12}

def blow(t):
    """0 at rest, 1 at the bottom of a blow: a quick strike and a slower lift."""
    p = (t * blows / N) % 1.0
    return math.sin(p / 0.25 * math.pi / 2) if p < 0.25 else math.cos((p - 0.25) / 0.75 * math.pi / 2)

for t in range(0, N + 1, 2):
    s = blow(t)
    bt = 30 + t * 0.4  # drift slowly through the base so it doesn't look frozen
    for key, f in src.items():
        path, idx = key
        bone = path.split('"')[1]
        if bone in nod_bones and path.endswith('rotation_quaternion'):
            continue
        value = f.evaluate(bt)
        if bone == 'Body' and path.endswith('location') and idx == 1:
            value -= 0.06 * s
        put(build, key, t, value)
    for bone, deg in nod_bones.items():
        q = Quaternion([src.get((f'pose.bones["{bone}"].rotation_quaternion', i)).evaluate(bt) if src.get((f'pose.bones["{bone}"].rotation_quaternion', i)) else (1, 0, 0, 0)[i] for i in range(4)])
        q = q @ Quaternion(Vector((1, 0, 0)), math.radians(lean[bone] + deg * s) * SIGN)
        for i in range(4):
            put(build, (f'pose.bones["{bone}"].rotation_quaternion', i), t, q[i])
print('Building', N, 'frames on', base_name)

bpy.ops.wm.save_as_mainfile(filepath=OUT, copy=True)
print('saved', OUT)
