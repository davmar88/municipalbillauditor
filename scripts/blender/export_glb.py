"""Exports the open .blend's armature and mesh as a .glb with every action as an animation.

  blender -b --disable-autoexec --factory-startup Animal.blend --python export_glb.py -- <out.glb> [frame_step]
"""
import sys

import bpy

args = sys.argv[sys.argv.index('--') + 1:]
out = args[0]
step = int(args[1]) if len(args) > 1 else 1

arm = next(o for o in bpy.data.objects if o.type == 'ARMATURE')
# Leave the armature in its rest pose with no action playing, so the export's base pose is clean.
if arm.animation_data:
    arm.animation_data.action = None
    for track in arm.animation_data.nla_tracks:
        track.mute = False

props = bpy.ops.export_scene.gltf.get_rna_type().properties.keys()
options = dict(
    filepath=out,
    export_format='GLB',
    export_animations=True,
    export_animation_mode='ACTIONS',
    export_skins=True,
    export_morph=False,
    export_yup=True,
    export_apply=False,
    export_materials='EXPORT',
    export_texcoords=False,
    export_cameras=False,
    export_lights=False,
    export_extras=False,
    export_optimize_animation_size=True,
    export_frame_step=step,
    export_force_sampling=True,
    export_reset_pose_bones=True,
)
options = {k: v for k, v in options.items() if k in props or k == 'filepath'}
print('export options', sorted(options))
bpy.ops.export_scene.gltf(**options)
print('exported', out)
