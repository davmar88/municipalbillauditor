import { fireEvent, render, screen } from '@testing-library/react-native';

import { makeFinding } from '@/test/fixtures';

import { FindingCard } from '../FindingCard';

describe('FindingCard', () => {
  it('shows severity, confidence, title, explanation and overcharge', async () => {
    await render(<FindingCard finding={makeFinding()} onChangeStatus={jest.fn()} />);

    expect(screen.getByText('Medium')).toBeOnTheScreen();
    expect(screen.getByText('Confidence 80%')).toBeOnTheScreen();
    expect(screen.getByText('Water use is 3.1 times your usual')).toBeOnTheScreen();
    expect(screen.getByText(/may be a leak/)).toBeOnTheScreen();
    expect(screen.getByText('Possible overcharge: R980.00')).toBeOnTheScreen();
    expect(screen.getByText('Open')).toBeOnTheScreen();
  });

  it('dismisses an open finding', async () => {
    const onChangeStatus = jest.fn();
    await render(<FindingCard finding={makeFinding()} onChangeStatus={onChangeStatus} />);

    await fireEvent.press(screen.getByRole('button', { name: 'Dismiss: Water use is 3.1 times your usual' }));

    expect(onChangeStatus).toHaveBeenCalledWith('dismissed');
    expect(screen.queryByRole('button', { name: /Reopen/ })).toBeNull();
  });

  it('reopens a dismissed finding', async () => {
    const onChangeStatus = jest.fn();
    await render(<FindingCard finding={makeFinding({ status: 'dismissed' })} onChangeStatus={onChangeStatus} />);

    expect(screen.getByText('Dismissed')).toBeOnTheScreen();
    expect(screen.queryByRole('button', { name: /Dismiss/ })).toBeNull();
    await fireEvent.press(screen.getByRole('button', { name: /Reopen/ }));

    expect(onChangeStatus).toHaveBeenCalledWith('open');
  });

  it('labels low-severity findings "For information" and hides a missing overcharge', async () => {
    await render(
      <FindingCard
        finding={makeFinding({
          severity: 'low',
          confidence: 0.5,
          estimated_overcharge_cents: null,
          title: "Total doesn't match",
        })}
        onChangeStatus={jest.fn()}
      />,
    );

    expect(screen.getByText('For information')).toBeOnTheScreen();
    expect(screen.getByText('Confidence 50%')).toBeOnTheScreen();
    expect(screen.queryByText(/Possible overcharge/)).toBeNull();
  });

  it('offers no actions on a disputed finding', async () => {
    await render(<FindingCard finding={makeFinding({ status: 'disputed' })} onChangeStatus={jest.fn()} />);
    expect(screen.getByText('In a dispute')).toBeOnTheScreen();
    expect(screen.queryByRole('button')).toBeNull();
  });
});
