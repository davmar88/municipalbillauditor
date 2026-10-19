"""Renders preview pictures of an animal .blend: three-quarter, side (walking) and a head close-up.

  blender -b --disable-autoexec --factory-startup Animal.blend --python render_preview.py -- <out_prefix> [mesh_name]
"""
import math
import sys

import bpy
from mathutils import Vector

args = sys.argv[sys.argv.index('--') + 1:]
prefix = args[0]
ACTION, FRAME = args[1], int(args[2])

scene = bpy.context.scene
arm = next(o for o in bpy.data.objects if o.type == 'ARMATURE')
mesh = next(o for o in bpy.data.objects if o.type == 'MESH')

scene.render.engine = 'BLENDER_WORKBENCH'
scene.render.resolution_x, scene.render.resolution_y = 900, 700
scene.render.film_transparent = False
shading = scene.display.shading
shading.light = 'STUDIO'
shading.color_type = 'MATERIAL'
shading.show_shadows = True
shading.show_cavity = True
shading.cavity_type = 'WORLD'
shading.background_type = 'VIEWPORT'
shading.background_color = (0.016, 0.017, 0.024)
scene.display.render_aa = '8'
scene.render.image_settings.file_format = 'PNG'

# A floor so the animal stands on something.
bpy.ops.mesh.primitive_plane_add(size=40, location=(0, 0, 0))
floor = bpy.context.active_object
mat = bpy.data.materials.new('Floor')
mat.diffuse_color = (0.05, 0.05, 0.065, 1)
floor.data.materials.append(mat)

cam_data = bpy.data.cameras.new('Cam')
cam_data.lens = 50
cam = bpy.data.objects.new('Cam', cam_data)
scene.collection.objects.link(cam)
scene.camera = cam

def pose(action_name, frame):
    if arm.animation_data is None:
        arm.animation_data_create()
    for track in arm.animation_data.nla_tracks:
        track.mute = True
    arm.animation_data.action = bpy.data.actions[action_name]
    if hasattr(arm.animation_data, 'action_slot') and arm.animation_data.action.slots:
        arm.animation_data.action_slot = arm.animation_data.action.slots[0]
    scene.frame_set(frame)

def look(position, target, lens=50):
    cam.location = Vector(position)
    direction = Vector(target) - cam.location
    cam.rotation_euler = direction.to_track_quat('-Z', 'Y').to_euler()
    cam_data.lens = lens

def shot(name):
    scene.render.filepath = f'{prefix}_{name}.png'
    bpy.ops.render.render(write_still=True)
    print('rendered', scene.render.filepath)

# One pose, seen from the front three-quarter and a little above.
dims = mesh.dimensions
height = max(dims.z, dims.y * 0.8, 0.5)
pose(ACTION, FRAME)
body = arm.pose.bones['Body']
centre = arm.matrix_world @ body.head
look((height * 1.6, -height * 2.2, centre.z + height * 1.1), (0, -height * 0.1, centre.z + height * 0.15))
shot(f'{ACTION.lower()}')
