import { fireEvent, screen, waitFor } from '@testing-library/react-native';
import { router } from 'expo-router';

import * as api from '@/api';
import { makeBill, makeDispute, makeFinding, makeMetro, makeProperty } from '@/test/fixtures';
import { renderWithProviders } from '@/test/render';

import { BillDetailScreen } from '../BillDetailScreen';

jest.mock('expo-router', () => ({
  router: { push: jest.fn(), replace: jest.fn(), back: jest.fn(), canGoBack: jest.fn(() => true) },
  Stack: { Screen: () => null },
}));

jest.mock('@/api', () => ({
  ...jest.requireActual('@/api'),
  getBill: jest.fn(),
  getProperty: jest.fn(),
  listMetros: jest.fn(),
  updateFinding: jest.fn(),
  updateBill: jest.fn(),
  createDispute: jest.fn(),
}));

const mocked = api as jest.Mocked<typeof api>;

const findings = [
  makeFinding({ id: 7, severity: 'high', title: 'Estimated three months in a row' }),
  makeFinding({ id: 8, severity: 'medium', title: 'Water use is 3.1 times your usual' }),
  makeFinding({
    id: 9,
    severity: 'low',
    confidence: 0.5,
    title: "Total doesn't match the line items",
    estimated_overcharge_cents: null,
  }),
];

beforeEach(() => {
  jest.clearAllMocks();
  mocked.getBill.mockResolvedValue(
    makeBill({ findings, findings_summary: { open_count: 2, high_count: 1, potential_overcharge_cents: 196000 } }),
  );
  mocked.getProperty.mockResolvedValue(makeProperty());
  mocked.listMetros.mockResolvedValue([makeMetro({ verified: false })]);
});

describe('BillDetailScreen', () => {
  it('shows the bill, its line items and an unverified deadline hint', async () => {
    await renderWithProviders(<BillDetailScreen billId={5} />);

    expect(await screen.findByText('Bill of 25 Sep 2026')).toBeOnTheScreen();
    expect(screen.getByText('R4 123.50')).toBeOnTheScreen();
    expect(screen.getByText('R1 523.40')).toBeOnTheScreen();
    expect(screen.getByText('25 Oct 2026')).toBeOnTheScreen();
    expect(await screen.findByTestId('unverified-hint')).toHaveTextContent(/unverified default for this municipality/);
    expect(screen.getByText('For information')).toBeOnTheScreen();
    expect(
      screen.getByText('2 possible problems to look at, with a possible overcharge of R1 960.00.'),
    ).toBeOnTheScreen();
  });

  it("doesn't count information-only notes as problems to look at", async () => {
    mocked.getBill.mockResolvedValue(
      makeBill({
        findings: [findings[2]],
        findings_summary: { open_count: 0, high_count: 0, potential_overcharge_cents: 0 },
      }),
    );
    await renderWithProviders(<BillDetailScreen billId={5} />);

    expect(
      await screen.findByText('Nothing here looks like it needs action. The notes below are for your information.'),
    ).toBeOnTheScreen();
    expect(screen.queryByText(/0 possible problems/)).toBeNull();
    expect(screen.getByText('For information')).toBeOnTheScreen();
  });

  it('dismisses a finding through the API', async () => {
    mocked.updateFinding.mockResolvedValue(makeFinding({ id: 8, status: 'dismissed' }));
    await renderWithProviders(<BillDetailScreen billId={5} />);

    await fireEvent.press(await screen.findByRole('button', { name: 'Dismiss: Water use is 3.1 times your usual' }));

    await waitFor(() => expect(mocked.updateFinding).toHaveBeenCalledWith(8, { status: 'dismissed' }));
    // The bill is fetched again so totals reflect the change.
    await waitFor(() => expect(mocked.getBill).toHaveBeenCalledTimes(2));
  });

  it('starts a dispute with medium and high findings preselected', async () => {
    mocked.createDispute.mockResolvedValue(makeDispute({ id: 4, finding_ids: [7, 8] }));
    await renderWithProviders(<BillDetailScreen billId={5} />);

    await fireEvent.press(await screen.findByRole('button', { name: 'Start a dispute' }));

    expect(screen.getByRole('checkbox', { name: 'Estimated three months in a row' })).toBeChecked();
    expect(screen.getByRole('checkbox', { name: 'Water use is 3.1 times your usual' })).toBeChecked();
    expect(screen.getByRole('checkbox', { name: "Total doesn't match the line items" })).not.toBeChecked();

    await fireEvent.press(screen.getByRole('button', { name: 'Draft dispute letter' }));

    await waitFor(() => expect(mocked.createDispute).toHaveBeenCalledWith(5, { finding_ids: [7, 8] }));
    await waitFor(() => expect(router.push).toHaveBeenCalledWith({ pathname: '/dispute/[id]', params: { id: '4' } }));
  });

  it('leaves out a finding you dismiss while choosing what to dispute', async () => {
    mocked.updateFinding.mockResolvedValue(makeFinding({ id: 8, status: 'dismissed' }));
    mocked.createDispute.mockResolvedValue(makeDispute({ id: 4, finding_ids: [7] }));
    await renderWithProviders(<BillDetailScreen billId={5} />);

    await fireEvent.press(await screen.findByRole('button', { name: 'Start a dispute' }));
    expect(screen.getByRole('checkbox', { name: 'Water use is 3.1 times your usual' })).toBeChecked();

    // The finding cards stay visible above the panel, so a finding can be dismissed mid-way.
    mocked.getBill.mockResolvedValue(
      makeBill({
        findings: [findings[0], { ...findings[1], status: 'dismissed' }, findings[2]],
        findings_summary: { open_count: 1, high_count: 1, potential_overcharge_cents: 98000 },
      }),
    );
    await fireEvent.press(screen.getByRole('button', { name: 'Dismiss: Water use is 3.1 times your usual' }));
    await waitFor(() =>
      expect(screen.queryByRole('checkbox', { name: 'Water use is 3.1 times your usual' })).toBeNull(),
    );

    await fireEvent.press(screen.getByRole('button', { name: 'Draft dispute letter' }));

    await waitFor(() => expect(mocked.createDispute).toHaveBeenCalledWith(5, { finding_ids: [7] }));
  });

  it('saves the usage worked out from a corrected reading, not the old one', async () => {
    mocked.updateBill.mockResolvedValue(makeBill());
    await renderWithProviders(<BillDetailScreen billId={5} />);

    await fireEvent.press(await screen.findByRole('button', { name: 'Edit bill details and line items' }));
    expect(screen.getByLabelText('Usage (optional)')).toHaveDisplayValue('38');

    await fireEvent.changeText(screen.getByLabelText('Current reading (optional)'), '1250');
    expect(screen.getByLabelText('Usage (optional)')).toHaveDisplayValue('47');

    await fireEvent.press(screen.getByRole('button', { name: 'Save and check bill' }));

    await waitFor(() => expect(mocked.updateBill).toHaveBeenCalled());
    expect(mocked.updateBill.mock.calls[0][1].line_items[0]).toMatchObject({
      previous_reading: 1203,
      current_reading: 1250,
      consumption: 47,
    });
  });

  it('asks for line items when the bill has only a file', async () => {
    mocked.getBill.mockResolvedValue(
      makeBill({
        status: 'needs_review',
        line_items: [],
        findings: [],
        has_file: true,
        file_mime: 'image/jpeg',
        bill_date: null,
        dispute_deadline: null,
      }),
    );
    await renderWithProviders(<BillDetailScreen billId={5} />);

    expect(await screen.findByText('Next step: type in your line items')).toBeOnTheScreen();
    expect(screen.getByRole('button', { name: 'Add a line from your bill' })).toBeOnTheScreen();
    expect(screen.getByRole('button', { name: 'View original' })).toBeOnTheScreen();
  });

  it('shows an error with a retry when the bill cannot be loaded', async () => {
    mocked.getBill.mockRejectedValue(new api.ApiError(404, "We couldn't find that. It may have been deleted."));
    await renderWithProviders(<BillDetailScreen billId={5} />);

    expect(await screen.findByText("We couldn't find that. It may have been deleted.")).toBeOnTheScreen();
    expect(screen.getByRole('button', { name: 'Try again' })).toBeOnTheScreen();
  });
});
