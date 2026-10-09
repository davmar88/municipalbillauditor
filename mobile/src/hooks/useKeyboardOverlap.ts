import { useCallback, useEffect, useRef, useState, type RefObject } from 'react';
import { Keyboard, Platform, type LayoutChangeEvent, type View } from 'react-native';

/** How far the top of the keyboard reaches into a view, in points (0 when it doesn't). */
export function keyboardOverlap(viewTop: number, viewHeight: number, keyboardTop: number): number {
  return Math.max(0, Math.round(viewTop + viewHeight - keyboardTop));
}

/**
 * How much of a view the on-screen keyboard covers, on Android.
 *
 * Android apps now draw edge to edge, so the system no longer shrinks the window when the
 * keyboard opens: fields near the bottom of a long form would stay hidden under it. Padding
 * the view by the covered height gives the same effect as the old resizing, and lets the
 * scroll view bring the focused field into sight. Measuring the overlap (rather than using
 * the keyboard height) means nothing is added if the window does get resized.
 * iOS is handled by ScrollView's automaticallyAdjustKeyboardInsets instead.
 *
 * Attach `viewRef` and the returned `onLayout` to the view to pad.
 */
export function useKeyboardOverlap(viewRef: RefObject<View | null>, enabled = Platform.OS === 'android') {
  const keyboardTop = useRef<number | null>(null);
  const [overlap, setOverlap] = useState(0);

  const measure = useCallback(() => {
    const top = keyboardTop.current;
    if (top === null) {
      setOverlap(0);
      return;
    }
    viewRef.current?.measureInWindow((_x, y, _width, height) => {
      // The keyboard may have closed while we were measuring.
      if (keyboardTop.current !== null) setOverlap(keyboardOverlap(y, height, keyboardTop.current));
    });
  }, [viewRef]);

  useEffect(() => {
    if (!enabled) return;
    const show = Keyboard.addListener('keyboardDidShow', (event) => {
      keyboardTop.current = event.endCoordinates.screenY;
      measure();
    });
    const hide = Keyboard.addListener('keyboardDidHide', () => {
      keyboardTop.current = null;
      setOverlap(0);
    });
    return () => {
      show.remove();
      hide.remove();
    };
  }, [enabled, measure]);

  // The padding sits inside the measured view, so its frame only changes for outside reasons
  // (rotation, a window resize); measure again then.
  const onLayout = useCallback((_event: LayoutChangeEvent) => measure(), [measure]);

  return { overlap: enabled ? overlap : 0, onLayout };
}
