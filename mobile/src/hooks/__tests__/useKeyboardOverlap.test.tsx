import { act, render, screen } from '@testing-library/react-native';
import { useRef } from 'react';
import {
  Keyboard,
  Platform,
  Text,
  View,
  type EmitterSubscription,
  type KeyboardEvent,
  type KeyboardEventName,
} from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { Screen } from '@/components/ui/Screen';

import { keyboardOverlap, useKeyboardOverlap } from '../useKeyboardOverlap';

type Listener = (event: KeyboardEvent) => void;

const listeners = new Map<KeyboardEventName, Listener>();
const measureInWindow = View.prototype.measureInWindow as jest.Mock;

/** A keyboard whose top edge is at `screenY` (in window points). */
function keyboardAt(screenY: number): KeyboardEvent {
  return {
    duration: 0,
    easing: 'keyboard',
    endCoordinates: { screenX: 0, screenY, width: 360, height: 640 - screenY },
  } as KeyboardEvent;
}

async function emit(name: KeyboardEventName, event: KeyboardEvent = keyboardAt(640)) {
  await act(async () => listeners.get(name)?.(event));
}

beforeEach(() => {
  listeners.clear();
  jest.spyOn(Keyboard, 'addListener').mockImplementation((name, listener) => {
    listeners.set(name, listener as Listener);
    return { remove: () => listeners.delete(name) } as unknown as EmitterSubscription;
  });
  // The view under test sits 80pt from the top of the window and is 560pt tall (bottom at 640).
  measureInWindow.mockImplementation((callback: (x: number, y: number, w: number, h: number) => void) =>
    callback(0, 80, 360, 560),
  );
});

afterEach(() => {
  jest.restoreAllMocks();
  measureInWindow.mockReset();
});

describe('keyboardOverlap', () => {
  it('is how far the keyboard reaches into the view', () => {
    expect(keyboardOverlap(80, 560, 400)).toBe(240);
    expect(keyboardOverlap(80, 300, 400)).toBe(0);
  });
});

function Probe({ enabled }: { enabled: boolean }) {
  const viewRef = useRef<View>(null);
  const keyboard = useKeyboardOverlap(viewRef, enabled);
  return (
    <View ref={viewRef} onLayout={keyboard.onLayout}>
      <Text>{`overlap:${keyboard.overlap}`}</Text>
    </View>
  );
}

describe('useKeyboardOverlap', () => {
  it('reports how much of the view the keyboard covers, and clears it when the keyboard closes', async () => {
    await render(<Probe enabled />);
    expect(screen.getByText('overlap:0')).toBeOnTheScreen();

    await emit('keyboardDidShow', keyboardAt(400));
    expect(screen.getByText('overlap:240')).toBeOnTheScreen();

    await emit('keyboardDidHide');
    expect(screen.getByText('overlap:0')).toBeOnTheScreen();
  });

  it('adds nothing when the window was already resized above the keyboard', async () => {
    measureInWindow.mockImplementation((callback: (x: number, y: number, w: number, h: number) => void) =>
      callback(0, 80, 360, 320),
    );
    await render(<Probe enabled />);
    await emit('keyboardDidShow', keyboardAt(400));
    expect(screen.getByText('overlap:0')).toBeOnTheScreen();
  });

  it('does nothing when turned off (iOS uses the scroll view’s own keyboard insets)', async () => {
    await render(<Probe enabled={false} />);
    expect(listeners.size).toBe(0);
  });
});

describe('Screen on Android', () => {
  const METRICS = { frame: { x: 0, y: 0, width: 360, height: 640 }, insets: { top: 0, left: 0, right: 0, bottom: 0 } };

  it('makes room for the keyboard so the last fields can be scrolled into view', async () => {
    jest.replaceProperty(Platform, 'OS', 'android');
    await render(
      <SafeAreaProvider initialMetrics={METRICS}>
        <Screen testID="form">
          <Text>Last field</Text>
        </Screen>
      </SafeAreaProvider>,
    );
    const keyboardArea = () => screen.getByTestId('form').parent;

    await emit('keyboardDidShow', keyboardAt(400));
    expect(keyboardArea()).toHaveStyle({ paddingBottom: 240 });

    await emit('keyboardDidHide');
    expect(keyboardArea()).toHaveStyle({ paddingBottom: 0 });
  });
});
