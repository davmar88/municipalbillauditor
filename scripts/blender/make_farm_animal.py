"""Readies a "More animals" pack animal (Cow, Horse, Llama, Pig, Pug, Sheep, Zebra) for the game.

  blender -b --disable-autoexec --factory-startup Animal.blend --python make_farm_animal.py -- <out.blend> [nod_sign]

The pack has Idle, Walk, WalkSlow, Run, Jump and Death on its own rig (Neck, Head,
Body...). The game also plays Eating, Fighting and Building, made here from those:
Eating: head down to the grass, nibbling.
Fighting: a jump and a head-butt.
Building: head low, knocking down like a hammer, the body bobbing with each blow.
"""
import math
import sys

import bpy
from bpy_extras import anim_utils
from mathutils import Quaternion, Vector

args = sys.argv[sys.argv.index('--') + 1:]
OUT = args[0]
# Which way the neck and head nod down about their X axis.
SIGN = float(args[1]) if len(args) > 1 else 1.0

acts = bpy.data.actions

# Plain metal on export otherwise: give every material a clean tree in its own colour.
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


def bag(action):
    return anim_utils.action_ensure_channelbag_for_slot(action, action.slots[0])


def curves(action):
    return {(f.data_path, f.array_index): f for f in bag(action).fcurves}


SLOT = acts['Idle'].slots[0].name_display


def new_action(name):
    if name in acts:
        acts.remove(acts[name])
    a = acts.new(name)
    a.use_fake_user = True
    a.slots.new('OBJECT', SLOT)
    return a


def put(dst, key, frame, value):
    cb = bag(dst)
    f = cb.fcurves.find(key[0], index=key[1]) or cb.fcurves.new(key[0], index=key[1])
    f.keyframe_points.insert(frame, value, options={'FAST'})


def quat(src, bone, t):
    return Quaternion([
        src[(f'pose.bones["{bone}"].rotation_quaternion', i)].evaluate(t)
        if (f'pose.bones["{bone}"].rotation_quaternion', i) in src else (1, 0, 0, 0)[i]
        for i in range(4)
    ])


def bend(dst, src, frame, t, turns):
    """Copy src at time t into dst at frame, with the named bones turned down by so many degrees."""
    for key, f in src.items():
        path, idx = key
        bone = path.split('"')[1]
        if bone in turns and path.endswith('rotation_quaternion'):
            continue
        put(dst, key, frame, f.evaluate(t))
    for bone, deg in turns.items():
        q = quat(src, bone, t) @ Quaternion(Vector((1, 0, 0)), math.radians(deg) * SIGN)
        for i in range(4):
            put(dst, (f'pose.bones["{bone}"].rotation_quaternion', i), frame, q[i])


idle = curves(acts['Idle'])

# ---- Eating ---------------------------------------------------------------------------
eat = new_action('Eating')
N = 60
for t in range(0, N + 1, 2):
    nibble = math.sin(t / N * math.pi * 6)
    bend(eat, idle, t, 20 + t * 0.3, {'Neck': 38, 'Head': 22 + 6 * nibble})

# ---- Fighting -------------------------------------------------------------------------
fight = new_action('Fighting')
jump = curves(acts['Jump'])
J = int(round(acts['Jump'].frame_range[1]))
for t in range(0, J + 1, 2):
    p = t / J
    # Head down as it lands, butting forward.
    butt = math.sin(min(1.0, max(0.0, (p - 0.45) / 0.4)) * math.pi)
    bend(fight, jump, t, t, {'Neck': 18 * butt, 'Head': 24 * butt})
print('Fighting', J, 'frames')

# ---- Building -------------------------------------------------------------------------
build = new_action('Building')
N = 48
blows = 4


def blow(t):
    p = (t * blows / N) % 1.0
    return math.sin(p / 0.25 * math.pi / 2) if p < 0.25 else math.cos((p - 0.25) / 0.75 * math.pi / 2)


for t in range(0, N + 1, 2):
    s = blow(t)
    bend(build, idle, t, 30 + t * 0.4, {'Neck': 20 + 6 * s, 'Head': 6 + 24 * s})
    body = ('pose.bones["Body"].location', 1)
    if body in idle:
        put(build, body, t, idle[body].evaluate(30 + t * 0.4) - 0.04 * s)

bpy.ops.wm.save_as_mainfile(filepath=OUT, copy=True)
print('saved', OUT)
