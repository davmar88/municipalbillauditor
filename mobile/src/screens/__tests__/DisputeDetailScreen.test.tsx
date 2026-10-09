import { fireEvent, screen, waitFor } from '@testing-library/react-native';
import * as Clipboard from 'expo-clipboard';

import * as api from '@/api';
import { makeDispute, makeMetro } from '@/test/fixtures';
import { renderWithProviders } from '@/test/render';

import { DisputeDetailScreen } from '../DisputeDetailScreen';

jest.mock('expo-router', () => ({
  router: { push: jest.fn(), replace: jest.fn(), back: jest.fn(), canGoBack: jest.fn(() => true) },
  Stack: { Screen: () => null },
}));

jest.mock('expo-clipboard', () => ({ setStringAsync: jest.fn(() => Promise.resolve(true)) }));

jest.mock('@/api', () => ({
  ...jest.requireActual('@/api'),
  getDispute: jest.fn(),
  listMetros: jest.fn(),
  updateDispute: jest.fn(),
  submitDispute: jest.fn(),
}));

const mocked = api as jest.Mocked<typeof api>;

beforeEach(() => {
  jest.clearAllMocks();
  mocked.listMetros.mockResolvedValue([makeMetro()]);
});

describe('DisputeDetailScreen', () => {
  it('lets you edit, save and copy a draft letter', async () => {
    const draft = makeDispute();
    mocked.getDispute.mockResolvedValue(draft);
    mocked.updateDispute.mockResolvedValue({ ...draft, letter_body: 'New body' });
    await renderWithProviders(<DisputeDetailScreen disputeId={4} />);

    const body = await screen.findByLabelText('Letter');
    await fireEvent.changeText(body, 'New body');
    await fireEvent.press(screen.getByRole('button', { name: 'Save changes' }));
    await waitFor(() =>
      expect(mocked.updateDispute).toHaveBeenCalledWith(4, {
        letter_subject: draft.letter_subject,
        letter_body: 'New body',
      }),
    );

    await fireEvent.press(screen.getByRole('button', { name: 'Copy letter' }));
    expect(Clipboard.setStringAsync).toHaveBeenCalledWith(`${draft.letter_subject}\n\nNew body`);
    expect(await screen.findByRole('button', { name: 'Letter copied' })).toBeOnTheScreen();
  });

  it('shows the dispute channels and marks the dispute as submitted', async () => {
    mocked.getDispute.mockResolvedValue(makeDispute());
    mocked.submitDispute.mockResolvedValue(makeDispute({ status: 'submitted', channel: 'email' }));
    await renderWithProviders(<DisputeDetailScreen disputeId={4} />);

    expect(await screen.findByText('Billing query channel')).toBeOnTheScreen();
    expect(screen.getByText(/unverified defaults/)).toBeOnTheScreen();

    await fireEvent.press(screen.getByRole('button', { name: 'Mark as submitted' }));
    expect(screen.getByText('Choose how you sent the letter.')).toBeOnTheScreen();

    await fireEvent.press(screen.getByRole('button', { name: /How did you send it\?/ }));
    await fireEvent.press(screen.getByRole('radio', { name: 'Email' }));
    await fireEvent.changeText(screen.getByLabelText('Municipality reference number (optional)'), 'QRY-889123');
    await fireEvent.press(screen.getByRole('button', { name: 'Mark as submitted' }));

    await waitFor(() =>
      expect(mocked.submitDispute).toHaveBeenCalledWith(4, { channel: 'email', municipality_reference: 'QRY-889123' }),
    );
  });

  it('offers escalation only when there is a next step', async () => {
    mocked.getDispute.mockResolvedValue(
      makeDispute({
        status: 'submitted',
        channel: 'email',
        submitted_at: '2026-10-09T08:00:00.000000Z',
        response_due_at: '2026-11-08T08:00:00.000000Z',
        next_step: {
          name: 'Senior revenue official',
          description: 'Ask a senior official to review it.',
          due_at: '2026-11-08T08:00:00.000000Z',
        },
      }),
    );
    await renderWithProviders(<DisputeDetailScreen disputeId={4} />);

    expect(await screen.findByRole('button', { name: 'Escalate to Senior revenue official' })).toBeOnTheScreen();
    expect(screen.getByText('Ask a senior official to review it.')).toBeOnTheScreen();
    expect(screen.queryByRole('button', { name: 'Delete draft' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Close dispute' })).toBeOnTheScreen();
  });

  it('hides escalation for a closed dispute', async () => {
    mocked.getDispute.mockResolvedValue(
      makeDispute({ status: 'resolved', outcome_amount_cents: 98000, resolved_at: '2026-11-01T08:00:00.000000Z' }),
    );
    await renderWithProviders(<DisputeDetailScreen disputeId={4} />);

    expect(await screen.findByLabelText('Recovered: R980.00')).toBeOnTheScreen();
    expect(screen.getByLabelText('Closed on: 1 Nov 2026')).toBeOnTheScreen();
    expect(screen.queryByRole('button', { name: /Escalate/ })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Close dispute' })).toBeNull();
  });
});
