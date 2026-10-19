"""Builds a low-poly hen or raven in the pack's style, with a bird skeleton and its animations.

  blender -b --factory-startup --python make_bird.py -- <out_dir> hen|raven
"""
import math
import sys
from pathlib import Path

import bmesh
import bpy
from mathutils import Euler, Matrix, Vector

args = sys.argv[sys.argv.index('--') + 1:]
OUT = Path(args[0])
KIND = args[1]
OUT.mkdir(parents=True, exist_ok=True)

# Start from an empty scene.
bpy.ops.wm.read_factory_settings(use_empty=True)
scene = bpy.context.scene
scene.render.fps = 30

def srgb(hexcode):
    h = hexcode.lstrip('#')
    out = []
    for i in (0, 2, 4):
        c = int(h[i:i + 2], 16) / 255
        out.append(c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4)
    return (*out, 1.0)

PALETTE = {
    'hen': {'Main': '#8a5230', 'Main_Light': '#c98a52', 'Main_Dark': '#4d2c1a', 'Beak': '#e2b03a', 'Comb': '#c8302c', 'Legs': '#e2b03a', 'Eye_Black': '#141414', 'Eye_White': '#e6e6e6'},
    'raven': {'Main': '#1f1d29', 'Main_Light': '#2a2738', 'Main_Dark': '#121118', 'Beak': '#2c2b31', 'Comb': '#2c2b31', 'Legs': '#2c2b31', 'Eye_Black': '#0b0b0b', 'Eye_White': '#d9d9d9'},
}[KIND]

MATS = {}
for name, colour in PALETTE.items():
    mat = bpy.data.materials.new(name)
    mat.use_nodes = True
    nt = mat.node_tree
    nt.nodes.clear()
    out = nt.nodes.new('ShaderNodeOutputMaterial')
    bsdf = nt.nodes.new('ShaderNodeBsdfPrincipled')
    nt.links.new(bsdf.outputs['BSDF'], out.inputs['Surface'])
    bsdf.inputs['Base Color'].default_value = srgb(colour)
    bsdf.inputs['Roughness'].default_value = 0.9 if name != 'Main_Light' else 0.6
    bsdf.inputs['Metallic'].default_value = 0.0
    mat.diffuse_color = srgb(colour)
    MATS[name] = mat
MAT_ORDER = list(PALETTE)

# ---- shape --------------------------------------------------------------------
# Blender axes: Z up, the bird faces -Y (like the pack, which exports facing +Z).
H = {
    'hen': dict(body=((0, 0.05, 0.62), (0.33, 0.42, 0.36), -8), head=((0, -0.38, 1.04), 0.15), neck=((0, -0.27, 0.8), (0, -0.36, 0.97), 0.13, 0.1),
                beak=((0, -0.5, 1.02), 0.13, 0.05, -18), wing=((0.29, 0.08, 0.66), (0.07, 0.3, 0.2)), legx=0.12, hip=0.5, knee=0.32, ankle=0.06),
    'raven': dict(body=((0, 0.06, 0.6), (0.27, 0.5, 0.29), -4), head=((0, -0.46, 0.86), 0.15), neck=((0, -0.34, 0.72), (0, -0.42, 0.83), 0.14, 0.12),
                  beak=((0, -0.6, 0.85), 0.24, 0.065, -14), wing=((0.25, 0.14, 0.66), (0.06, 0.48, 0.18)), legx=0.1, hip=0.48, knee=0.3, ankle=0.06),
}[KIND]

parts = []  # (bmesh, material name, bone name or a function of position giving bone weights)

def finish(bm, mat, bone):
    parts.append((bm, mat, bone))

def ellipsoid(centre, radii, segments=10, rings=7, tilt=0.0, mat='Main', bone='Body', squash_top=1.0):
    bm = bmesh.new()
    bmesh.ops.create_uvsphere(bm, u_segments=segments, v_segments=rings, radius=1.0)
    for v in bm.verts:
        v.co.x *= radii[0]
        v.co.y *= radii[1]
        v.co.z *= radii[2] * (squash_top if v.co.z > 0 else 1)
    bmesh.ops.rotate(bm, verts=bm.verts, cent=(0, 0, 0), matrix=Matrix.Rotation(math.radians(tilt), 3, 'X'))
    bmesh.ops.translate(bm, verts=bm.verts, vec=Vector(centre))
    finish(bm, mat, bone)
    return bm

def tube(a, b, ra, rb, sides=8, mat='Main', bone='Body', cap=True):
    a, b = Vector(a), Vector(b)
    axis = (b - a).normalized()
    side = axis.cross(Vector((1, 0, 0)))
    if side.length < 1e-6:
        side = axis.cross(Vector((0, 1, 0)))
    side.normalize()
    up = axis.cross(side).normalized()
    bm = bmesh.new()
    ring_a = [bm.verts.new(a + (side * math.cos(2 * math.pi * k / sides) + up * math.sin(2 * math.pi * k / sides)) * ra) for k in range(sides)]
    ring_b = [bm.verts.new(b + (side * math.cos(2 * math.pi * k / sides) + up * math.sin(2 * math.pi * k / sides)) * rb) for k in range(sides)]
    for k in range(sides):
        bm.faces.new((ring_a[k], ring_a[(k + 1) % sides], ring_b[(k + 1) % sides], ring_b[k]))
    if cap:
        bm.faces.new(list(reversed(ring_a)))
        if rb > 1e-4:
            bm.faces.new(ring_b)
    bmesh.ops.recalc_face_normals(bm, faces=bm.faces)
    finish(bm, mat, bone)
    return bm

def cone(base, tip, radius, sides=6, mat='Beak', bone='Head'):
    base, tip = Vector(base), Vector(tip)
    axis = (tip - base).normalized()
    side = axis.cross(Vector((0, 0, 1)))
    if side.length < 1e-6:
        side = axis.cross(Vector((1, 0, 0)))
    side.normalize()
    up = axis.cross(side).normalized()
    bm = bmesh.new()
    ring = [bm.verts.new(base + (side * math.cos(2 * math.pi * k / sides) + up * math.sin(2 * math.pi * k / sides)) * radius) for k in range(sides)]
    top = bm.verts.new(tip)
    for k in range(sides):
        bm.faces.new((ring[k], ring[(k + 1) % sides], top))
    bm.faces.new(list(reversed(ring)))
    bmesh.ops.recalc_face_normals(bm, faces=bm.faces)
    finish(bm, mat, bone)
    return bm

def blade(root, direction, length, width, thickness, mat='Main_Dark', bone='Tail', sides_up=Vector((0, 0, 1))):
    """A flat tapered feather."""
    root, d = Vector(root), Vector(direction).normalized()
    w = d.cross(sides_up).normalized() * width
    t = d.cross(w).normalized() * thickness
    tip = root + d * length
    mid = root + d * length * 0.55
    bm = bmesh.new()
    vs = [bm.verts.new(p) for p in (root - w * 0.5 + t, root + w * 0.5 + t, mid + w + t, tip + t, mid - w + t,
                                    root - w * 0.5 - t, root + w * 0.5 - t, mid + w - t, tip - t, mid - w - t)]
    top, bot = vs[:5], vs[5:]
    bm.faces.new(top)
    bm.faces.new(list(reversed(bot)))
    for k in range(5):
        bm.faces.new((top[k], bot[k], bot[(k + 1) % 5], top[(k + 1) % 5]))
    bmesh.ops.recalc_face_normals(bm, faces=bm.faces)
    finish(bm, mat, bone)
    return bm

body_c, body_r, body_tilt = H['body']
ellipsoid(body_c, body_r, segments=10, rings=8, tilt=body_tilt, mat='Main', bone='Body')
# A lighter breast.
ellipsoid((body_c[0], body_c[1] - body_r[1] * 0.45, body_c[2] - body_r[2] * 0.1), (body_r[0] * 0.8, body_r[1] * 0.55, body_r[2] * 0.8), segments=8, rings=6, mat='Main_Light', bone='Body')

head_c, head_r = H['head']
ellipsoid(head_c, (head_r * 0.92, head_r * 1.05, head_r), segments=8, rings=6, mat='Main', bone='Head')
na, nb, nra, nrb = H['neck']
tube(na, nb, nra, nrb, sides=8, mat='Main', bone='NECK', cap=False)

beak_base, beak_len, beak_r, beak_tilt = H['beak']
bd = Vector((0, -math.cos(math.radians(beak_tilt)), math.sin(math.radians(beak_tilt))))
cone(beak_base, Vector(beak_base) + bd * beak_len, beak_r, sides=6, mat='Beak', bone='Head')
if KIND == 'raven':
    # A heavier, slightly hooked beak and a shaggy throat.
    # The hook at the tip.
    cone(Vector(beak_base) + bd * beak_len * 0.8, Vector(beak_base) + bd * beak_len * 0.98 + Vector((0, 0, -0.055)), beak_r * 0.42, sides=5, mat='Beak', bone='Head')
    for k, x in enumerate((-0.05, 0.0, 0.05)):
        cone(Vector((x, head_c[1] + 0.05, head_c[2] - head_r * 0.75)), Vector((x * 1.3, head_c[1] + 0.02 - 0.03 * k, head_c[2] - head_r * 1.55)), 0.045, sides=4, mat='Main_Dark', bone='Head')

# Eyes, one each side.
for sx in (1, -1):
    ex, ey, ez = sx * head_r * 0.78, head_c[1] - head_r * 0.45, head_c[2] + head_r * 0.25
    rim = 0.03 if KIND == 'hen' else 0.027
    ellipsoid((ex, ey, ez), (rim, rim, rim), segments=6, rings=4, mat='Eye_White', bone='Head')
    ellipsoid((ex + sx * 0.012, ey - 0.003, ez), (0.025, 0.025, 0.025), segments=6, rings=4, mat='Eye_Black', bone='Head')

if KIND == 'hen':
    # Comb on top, wattle under the beak.
    for k, (y, h) in enumerate(((-0.46, 0.08), (-0.39, 0.11), (-0.32, 0.09), (-0.26, 0.06))):
        cone((0, y, head_c[2] + head_r * 0.85), (0, y + 0.01, head_c[2] + head_r * 0.85 + h), 0.04, sides=5, mat='Comb', bone='Head')
    ellipsoid((0, -0.49, head_c[2] - head_r * 0.62), (0.03, 0.035, 0.06), segments=6, rings=4, mat='Comb', bone='Head')

# Wings, lying along each side; three bones each so they can fold and flap.
wing_c, wing_r = H['wing']
for sx, side in ((1, 'L'), (-1, 'R')):
    ellipsoid((sx * wing_c[0], wing_c[1], wing_c[2]), wing_r, segments=8, rings=6, tilt=-12, mat='Main_Dark', bone=f'WING.{side}', squash_top=0.8)

# Tail feathers.
if KIND == 'hen':
    tail_root = Vector((0, body_c[1] + body_r[1] * 0.75, body_c[2] + body_r[2] * 0.35))
    for k, ang in enumerate((-22, -8, 6, 20)):
        d = Vector((math.sin(math.radians(ang)) * 0.4, 0.55, 0.85))
        blade(tail_root + Vector((math.sin(math.radians(ang)) * 0.05, 0, 0)), d, 0.34 - abs(ang) * 0.003, 0.07, 0.02, mat='Main_Dark', bone='Tail', sides_up=Vector((0, -0.85, 0.55)))
else:
    tail_root = Vector((0, body_c[1] + body_r[1] * 0.8, body_c[2] + body_r[2] * 0.1))
    for k, ang in enumerate((-14, -5, 5, 14)):
        d = Vector((math.sin(math.radians(ang)) * 0.6, 1.0, -0.12))
        blade(tail_root, d, 0.42 if abs(ang) < 10 else 0.36, 0.09, 0.02, mat='Main_Dark', bone='Tail')

# Legs and feet.
lx, hip, knee, ankle = H['legx'], H['hip'], H['knee'], H['ankle']
for sx, side in ((1, 'L'), (-1, 'R')):
    x = sx * lx
    tube((x, 0.06, knee + 0.04), (x, 0.06, ankle), 0.032, 0.026, sides=6, mat='Legs', bone=f'LowerLeg.{side}')
    for d in (Vector((0, -1, -0.15)), Vector((sx * 0.6, -0.8, -0.15)), Vector((-sx * 0.6, -0.8, -0.15)), Vector((0, 1, -0.1))):
        L = 0.15 if d.y < 0 else 0.08
        tube((x, 0.06, ankle - 0.02), Vector((x, 0.06, ankle - 0.02)) + d.normalized() * L, 0.018, 0.01, sides=4, mat='Legs', bone=f'Foot.{side}')

# ---- one mesh -------------------------------------------------------------------------
me = bpy.data.meshes.new(KIND.capitalize())
bm_all = bmesh.new()
assign = []  # bone spec per vertex, in order
for bm, mat, bone in parts:
    offset = len(bm_all.verts)
    vmap = {}
    for v in bm.verts:
        nv = bm_all.verts.new(v.co)
        vmap[v] = nv
        assign.append((bone, v.co.copy()))
    bm_all.verts.ensure_lookup_table()
    for f in bm.faces:
        nf = bm_all.faces.new([vmap[v] for v in f.verts])
        nf.material_index = MAT_ORDER.index(mat)
        nf.smooth = False
    bm.free()
bm_all.to_mesh(me)
bm_all.free()
for name in MAT_ORDER:
    me.materials.append(MATS[name])
ob = bpy.data.objects.new(KIND.capitalize(), me)
scene.collection.objects.link(ob)

# ---- skeleton ---------------------------------------------------------------------------
arm_data = bpy.data.armatures.new('AnimalArmature')
arm = bpy.data.objects.new('AnimalArmature', arm_data)
scene.collection.objects.link(arm)
bpy.context.view_layer.objects.active = arm
bpy.ops.object.mode_set(mode='EDIT')
eb = arm_data.edit_bones
def bone(name, head, tail, parent=None):
    b = eb.new(name)
    b.head, b.tail = Vector(head), Vector(tail)
    b.roll = 0
    if parent:
        b.parent = eb[parent]
    return b

bone('Body', (0, body_c[1], hip), (0, body_c[1], body_c[2] + body_r[2] * 0.6))
bone('Tail', (0, body_c[1] + body_r[1] * 0.7, body_c[2] + body_r[2] * 0.2), tail_root + Vector((0, 0.25, 0.15 if KIND == 'hen' else -0.02)), 'Body')
bone('Neck1', (0, na[1] + 0.04, na[2] - 0.06), (0, (na[1] + nb[1]) / 2, (na[2] + nb[2]) / 2), 'Body')
bone('Neck2', (0, (na[1] + nb[1]) / 2, (na[2] + nb[2]) / 2), (0, nb[1], nb[2]), 'Neck1')
bone('Head', (0, nb[1], nb[2]), (0, nb[1] - 0.2, nb[2] + 0.01), 'Neck2')
for sx, side in ((1, 'L'), (-1, 'R')):
    x = sx * (wing_c[0] - 0.01)
    y0, y1 = wing_c[1] - wing_r[1] * 0.85, wing_c[1] + wing_r[1] * 0.95
    p = [Vector((x, y0 + (y1 - y0) * t, wing_c[2] + wing_r[2] * 0.5 - 0.12 * t)) for t in (0, 0.4, 0.75, 1.0)]
    bone(f'Wing1.{side}', p[0], p[1], 'Body')
    bone(f'Wing2.{side}', p[1], p[2], f'Wing1.{side}')
    bone(f'Wing3.{side}', p[2], p[3], f'Wing2.{side}')
    x = sx * lx
    bone(f'UpperLeg.{side}', (x, body_c[1] - 0.02, hip), (x, 0.06, knee), 'Body')
    bone(f'LowerLeg.{side}', (x, 0.06, knee), (x, 0.065, ankle), f'UpperLeg.{side}')
    bone(f'Foot.{side}', (x, 0.065, ankle), (x, -0.1, 0.02), f'LowerLeg.{side}')
wing_spans = {side: (wing_c[1] - wing_r[1] * 0.85, wing_c[1] + wing_r[1] * 0.95) for side in ('L', 'R')}
neck_span = (na[2], nb[2])
bpy.ops.object.mode_set(mode='OBJECT')

# ---- skinning: each part follows its bone; the neck and wings blend along their length ---
groups = {}
def group(name):
    if name not in groups:
        groups[name] = ob.vertex_groups.new(name=name)
    return groups[name]
for b in arm_data.bones:
    group(b.name)
for i, (spec, co) in enumerate(assign):
    if spec == 'NECK':
        t = max(0.0, min(1.0, (co.z - neck_span[0]) / max(1e-6, neck_span[1] - neck_span[0])))
        weights = {'Neck1': max(0, 1 - 2 * t), 'Neck2': 1 - abs(2 * t - 1), 'Head': max(0, 2 * t - 1)}
    elif spec.startswith('WING.'):
        side = spec[-1]
        y0, y1 = wing_spans[side]
        t = max(0.0, min(1.0, (co.y - y0) / (y1 - y0)))
        weights = {f'Wing1.{side}': max(0, 1 - t / 0.5), f'Wing2.{side}': max(0, 1 - abs(t - 0.55) / 0.3), f'Wing3.{side}': max(0, (t - 0.6) / 0.4)}
        total = sum(weights.values()) or 1
        weights = {k: v / total for k, v in weights.items()}
    else:
        weights = {spec: 1.0}
    for name, w in weights.items():
        if w > 1e-3:
            group(name).add([i], w, 'REPLACE')
mod = ob.modifiers.new('Armature', 'ARMATURE')
mod.object = arm
ob.parent = arm

# ---- animations -----------------------------------------------------------------------------
arm.animation_data_create()
for pb in arm.pose.bones:
    pb.rotation_mode = 'XYZ'

def keys(name, length, frames, loop=True):
    """frames: {frame: {bone: {'rot': (x, y, z) degrees, 'loc': (x, y, z)}}}; missing bones rest."""
    action = bpy.data.actions.new(name)
    action.use_fake_user = True
    arm.animation_data.action = action
    used = sorted({b for f in frames.values() for b in f})
    for frame in sorted(frames):
        for b in used:
            pb = arm.pose.bones[b]
            spec = frames[frame].get(b, {})
            pb.rotation_euler = Euler([math.radians(a) for a in spec.get('rot', (0, 0, 0))], 'XYZ')
            pb.location = Vector(spec.get('loc', (0, 0, 0)))
            pb.keyframe_insert('rotation_euler', frame=frame)
            pb.keyframe_insert('location', frame=frame)
    if loop:
        first = frames[min(frames)]
        for b in used:
            pb = arm.pose.bones[b]
            spec = first.get(b, {})
            pb.rotation_euler = Euler([math.radians(a) for a in spec.get('rot', (0, 0, 0))], 'XYZ')
            pb.location = Vector(spec.get('loc', (0, 0, 0)))
            pb.keyframe_insert('rotation_euler', frame=length)
            pb.keyframe_insert('location', frame=length)
    for pb in arm.pose.bones:
        pb.rotation_euler = Euler((0, 0, 0))
        pb.location = Vector((0, 0, 0))
    return action

def wings(spread, flap, fold=0.0):
    """Wing pose: spread swings each wing out from the body; flap lifts it."""
    return {
        'Wing1.L': {'rot': (flap, 0, -spread)}, 'Wing1.R': {'rot': (flap, 0, spread)},
        'Wing2.L': {'rot': (fold, 0, -spread * 0.15)}, 'Wing2.R': {'rot': (fold, 0, spread * 0.15)},
        'Wing3.L': {'rot': (fold * 0.6, 0, 0)}, 'Wing3.R': {'rot': (fold * 0.6, 0, 0)},
    }

def merge(*poses):
    out = {}
    for p in poses:
        for b, spec in p.items():
            out.setdefault(b, {}).update(spec)
    return out

def legs(l_up, l_low, r_up, r_low, foot_l=0, foot_r=0):
    return {
        'UpperLeg.L': {'rot': (l_up, 0, 0)}, 'LowerLeg.L': {'rot': (l_low, 0, 0)}, 'Foot.L': {'rot': (foot_l, 0, 0)},
        'UpperLeg.R': {'rot': (r_up, 0, 0)}, 'LowerLeg.R': {'rot': (r_low, 0, 0)}, 'Foot.R': {'rot': (foot_r, 0, 0)},
    }

rest_wings = wings(0, 0)
rest_legs = legs(0, 0, 0, 0)
still = merge(rest_wings, rest_legs, {'Body': {}, 'Tail': {}, 'Neck1': {}, 'Neck2': {}, 'Head': {}})

def pose(*extra):
    return merge(still, *extra)

# Idle: breathing, a look round, the tail twitching.
keys('Idle', 60, {
    0: pose({'Body': {'loc': (0, 0, 0)}}),
    15: pose({'Body': {'loc': (0, 0.012, 0)}, 'Head': {'rot': (0, 0, 8)}, 'Tail': {'rot': (4, 0, 0)}}),
    30: pose({'Body': {'loc': (0, 0, 0)}, 'Head': {'rot': (-4, 0, 0)}}),
    45: pose({'Body': {'loc': (0, 0.012, 0)}, 'Head': {'rot': (0, 0, -8)}, 'Tail': {'rot': (-3, 0, 0)}}),
})
keys('Idle_2', 60, {
    0: pose(),
    12: pose({'Neck2': {'rot': (0, 0, 25)}, 'Head': {'rot': (0, 15, 20)}}),
    24: pose({'Neck2': {'rot': (0, 0, 25)}, 'Head': {'rot': (0, -10, 20)}}),
    36: pose({'Neck2': {'rot': (0, 0, -25)}, 'Head': {'rot': (0, 10, -20)}}),
    48: pose({'Neck2': {'rot': (0, 0, -25)}, 'Head': {'rot': (0, -15, -20)}}),
})

# Walk: legs in turn, the body bobbing and the head pushing back and forth.
bob = 0.03 if KIND == 'hen' else 0.02
keys('Walk', 24, {
    0: pose(legs(-28, 30, 22, 0, 10, -15), {'Body': {'loc': (0, 0, 0), 'rot': (0, 0, 4)}, 'Neck1': {'rot': (12, 0, 0)}, 'Head': {'rot': (-10, 0, 0)}}),
    6: pose(legs(0, 0, 0, 25, 0, 20), {'Body': {'loc': (0, bob, 0)}, 'Neck1': {'rot': (-10, 0, 0)}, 'Head': {'rot': (8, 0, 0)}}),
    12: pose(legs(22, 0, -28, 30, -15, 10), {'Body': {'loc': (0, 0, 0), 'rot': (0, 0, -4)}, 'Neck1': {'rot': (12, 0, 0)}, 'Head': {'rot': (-10, 0, 0)}}),
    18: pose(legs(0, 25, 0, 0, 20, 0), {'Body': {'loc': (0, bob, 0)}, 'Neck1': {'rot': (-10, 0, 0)}, 'Head': {'rot': (8, 0, 0)}}),
})
keys('Run', 14, {
    0: pose(legs(-40, 40, 30, 0, 15, -20), wings(25, 10), {'Body': {'rot': (12, 0, 0), 'loc': (0, 0, 0)}, 'Neck1': {'rot': (-15, 0, 0)}}),
    3: pose(legs(0, 0, 0, 35, 0, 25), wings(30, 25), {'Body': {'rot': (12, 0, 0), 'loc': (0, 0.06, 0)}, 'Neck1': {'rot': (-15, 0, 0)}}),
    7: pose(legs(30, 0, -40, 40, -20, 15), wings(25, 10), {'Body': {'rot': (12, 0, 0), 'loc': (0, 0, 0)}, 'Neck1': {'rot': (-15, 0, 0)}}),
    10: pose(legs(0, 35, 0, 0, 25, 0), wings(30, 25), {'Body': {'rot': (12, 0, 0), 'loc': (0, 0.06, 0)}, 'Neck1': {'rot': (-15, 0, 0)}}),
})

# Eating: pecking at the ground.
down = pose({'Body': {'rot': (20, 0, 0)}, 'Neck1': {'rot': (35, 0, 0)}, 'Neck2': {'rot': (15, 0, 0)}, 'Head': {'rot': (8, 0, 0)}, 'Tail': {'rot': (-12, 0, 0)}})
up = pose({'Body': {'rot': (14, 0, 0)}, 'Neck1': {'rot': (18, 0, 0)}, 'Neck2': {'rot': (6, 0, 0)}, 'Head': {'rot': (-4, 0, 0)}, 'Tail': {'rot': (-8, 0, 0)}})
keys('Eating', 60, {0: pose(), 10: up, 14: down, 18: up, 22: down, 26: up, 36: up, 40: down, 44: up, 52: pose()})

keys('Attack', 20, {
    0: pose(),
    6: pose(wings(35, 30), {'Body': {'rot': (-10, 0, 0)}, 'Neck1': {'rot': (-25, 0, 0)}, 'Head': {'rot': (-10, 0, 0)}}),
    10: pose(wings(20, 10), {'Body': {'rot': (25, 0, 0), 'loc': (0, 0, -0.06)}, 'Neck1': {'rot': (40, 0, 0)}, 'Head': {'rot': (15, 0, 0)}}),
    14: pose(wings(10, 0), {'Body': {'rot': (12, 0, 0)}, 'Neck1': {'rot': (15, 0, 0)}}),
}, loop=False)
keys('Idle_HitReact1', 14, {
    0: pose(),
    3: pose(wings(30, 25), {'Body': {'rot': (-15, 0, 6)}, 'Neck1': {'rot': (-20, 0, 0)}, 'Head': {'rot': (-15, 0, 10)}}),
    8: pose(wings(10, 5), {'Body': {'rot': (-5, 0, 2)}}),
    13: pose(),
}, loop=False)
keys('Death', 30, {
    0: pose(),
    6: pose(wings(40, 40), {'Body': {'rot': (-15, 0, 0)}, 'Head': {'rot': (-20, 0, 0)}}),
    16: pose(wings(20, 0), legs(-40, 30, -30, 20), {'Body': {'rot': (0, 0, 75), 'loc': (0, -0.15, 0)}, 'Neck1': {'rot': (0, 0, 20)}, 'Head': {'rot': (20, 0, 20)}}),
    30: pose(wings(25, -10), legs(-55, 40, -45, 30), {'Body': {'rot': (0, 0, 88), 'loc': (0, -0.2, 0)}, 'Neck1': {'rot': (10, 0, 30)}, 'Head': {'rot': (25, 0, 25)}}),
}, loop=False)

# Jump: crouch, spring up with a flap, land.
keys('Jump', 30, {
    0: pose(),
    6: pose(legs(-30, 50, -30, 50, 20, 20), {'Body': {'loc': (0, -0.12, 0), 'rot': (10, 0, 0)}}),
    11: pose(legs(15, -5, 15, -5, -20, -20), wings(70, 45), {'Body': {'loc': (0, 0.35, 0)}}),
    15: pose(legs(-20, 30, -20, 30, 10, 10), wings(70, -25), {'Body': {'loc': (0, 0.45, 0)}}),
    21: pose(legs(-25, 40, -25, 40, 15, 15), wings(40, 15), {'Body': {'loc': (0, 0.05, 0)}}),
    24: pose(legs(-30, 50, -30, 50, 20, 20), {'Body': {'loc': (0, -0.1, 0), 'rot': (8, 0, 0)}}),
    30: pose(),
}, loop=False)

if KIND == 'hen':
    keys('Flap', 24, {
        0: pose(),
        4: pose(wings(55, 50), {'Body': {'rot': (-8, 0, 0)}, 'Neck1': {'rot': (-15, 0, 0)}}),
        8: pose(wings(55, -20), {'Body': {'rot': (-8, 0, 0)}, 'Neck1': {'rot': (-15, 0, 0)}}),
        12: pose(wings(55, 50), {'Body': {'rot': (-8, 0, 0)}, 'Neck1': {'rot': (-15, 0, 0)}}),
        16: pose(wings(55, -20), {'Body': {'rot': (-8, 0, 0)}, 'Neck1': {'rot': (-15, 0, 0)}}),
        20: pose(wings(20, 10)),
    })
else:
    tucked = legs(55, -70, 55, -70, -30, -30)
    keys('Takeoff', 24, {
        0: pose(),
        5: pose(legs(-30, 50, -30, 50, 20, 20), wings(30, 20), {'Body': {'loc': (0, -0.1, 0), 'rot': (12, 0, 0)}}),
        10: pose(tucked, wings(85, 55), {'Body': {'loc': (0, 0.2, 0), 'rot': (-10, 0, 0)}}),
        15: pose(tucked, wings(85, -35), {'Body': {'loc': (0, 0.36, 0), 'rot': (-6, 0, 0)}}),
        20: pose(tucked, wings(85, 55), {'Body': {'loc': (0, 0.46, 0)}}),
        24: pose(tucked, wings(85, 0), {'Body': {'loc': (0, 0.5, 0)}}),
    }, loop=False)
    keys('Fly', 16, {
        0: pose(tucked, wings(85, 50), {'Body': {'loc': (0, 0.5, 0)}, 'Tail': {'rot': (6, 0, 0)}}),
        4: pose(tucked, wings(85, 0, -15), {'Body': {'loc': (0, 0.55, 0)}}),
        8: pose(tucked, wings(85, -40, 10), {'Body': {'loc': (0, 0.5, 0)}, 'Tail': {'rot': (-6, 0, 0)}}),
        12: pose(tucked, wings(85, 0, 15), {'Body': {'loc': (0, 0.45, 0)}}),
    })
    keys('Land', 24, {
        0: pose(tucked, wings(85, 0), {'Body': {'loc': (0, 0.5, 0)}}),
        6: pose(legs(-20, 20, -20, 20, 10, 10), wings(85, 45), {'Body': {'loc': (0, 0.3, 0), 'rot': (-20, 0, 0)}}),
        12: pose(legs(-25, 35, -25, 35, 15, 15), wings(80, -30), {'Body': {'loc': (0, 0.1, 0), 'rot': (-25, 0, 0)}}),
        17: pose(legs(-30, 50, -30, 50, 20, 20), wings(50, 20), {'Body': {'loc': (0, -0.08, 0), 'rot': (8, 0, 0)}}),
        24: pose(),
    }, loop=False)

# Fighting: wings up, lunging pecks and little hops, on a loop.
keys('Fighting', 24, {
    0: pose(wings(55, 35), {'Body': {'rot': (-10, 0, 0)}, 'Neck1': {'rot': (-20, 0, 0)}, 'Tail': {'rot': (10, 0, 0)}}),
    5: pose(wings(40, -15), legs(-15, 25, -15, 25, 10, 10), {'Body': {'rot': (22, 0, 0), 'loc': (0, 0.08, 0)}, 'Neck1': {'rot': (35, 0, 0)}, 'Head': {'rot': (15, 0, 0)}}),
    9: pose(wings(60, 45), {'Body': {'rot': (-6, 0, 0), 'loc': (0, 0.04, 0)}, 'Neck1': {'rot': (-15, 0, 0)}}),
    14: pose(wings(55, 30), {'Body': {'rot': (-10, 0, 4)}, 'Neck1': {'rot': (-20, 0, 0)}, 'Head': {'rot': (0, 0, 10)}}),
    18: pose(wings(40, -15), legs(-15, 25, -15, 25, 10, 10), {'Body': {'rot': (22, 0, -4), 'loc': (0, 0.08, 0)}, 'Neck1': {'rot': (35, 0, 0)}, 'Head': {'rot': (15, 0, -10)}}),
})
# Building: hammering at the ground in front with the beak.
lean = {'Body': {'rot': (14, 0, 0)}, 'Neck1': {'rot': (18, 0, 0)}, 'Tail': {'rot': (-8, 0, 0)}}
strike = {'Body': {'rot': (22, 0, 0), 'loc': (0, -0.03, 0)}, 'Neck1': {'rot': (38, 0, 0)}, 'Neck2': {'rot': (14, 0, 0)}, 'Head': {'rot': (10, 0, 0)}, 'Tail': {'rot': (-14, 0, 0)}}
keys('Building', 24, {
    0: pose(wings(10, 10), lean),
    3: pose(wings(14, 18), strike),
    8: pose(wings(10, 10), lean, {'Head': {'rot': (-8, 0, 0)}}),
    12: pose(wings(10, 10), lean),
    15: pose(wings(14, 18), strike),
    20: pose(wings(10, 10), lean, {'Head': {'rot': (-8, 0, 0)}}),
})

arm.animation_data.action = bpy.data.actions['Idle']
print(KIND, 'mesh', len(me.vertices), 'verts', len(me.polygons), 'faces', sum(len(p.vertices) - 2 for p in me.polygons), 'triangles')
print('bones', [b.name for b in arm_data.bones])
print('actions', [a.name for a in bpy.data.actions])
bpy.ops.wm.save_as_mainfile(filepath=str(OUT / f'{KIND.capitalize()}.blend'))
print('saved', OUT / f'{KIND.capitalize()}.blend')
