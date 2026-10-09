import { fireEvent, screen } from '@testing-library/react-native';
import { router } from 'expo-router';

import * as api from '@/api';
import { makeMetro, makeUser } from '@/test/fixtures';
import { renderWithProviders } from '@/test/render';

import { DashboardScreen } from '../DashboardScreen';

jest.mock('expo-router', () => ({
  router: { push: jest.fn(), replace: jest.fn(), back: jest.fn(), canGoBack: jest.fn(() => true) },
  Stack: { Screen: () => null },
}));

jest.mock('@/api', () => ({
  ...jest.requireActual('@/api'),
  getDashboard: jest.fn(),
  listProperties: jest.fn(),
  getMe: jest.fn(),
  listMetros: jest.fn(),
}));

const mocked = api as jest.Mocked<typeof api>;

const emptyDashboard: api.Dashboard = {
  properties_count: 0,
  open_findings_count: 0,
  potential_overcharge_cents: 0,
  active_disputes_count: 0,
  recovered_cents: 0,
  upcoming_deadlines: [],
};

beforeEach(() => {
  jest.clearAllMocks();
  mocked.getMe.mockResolvedValue(makeUser());
  mocked.listMetros.mockResolvedValue([makeMetro()]);
});

describe('DashboardScreen', () => {
  it('guides a new user to add a property', async () => {
    mocked.getDashboard.mockResolvedValue(emptyDashboard);
    mocked.listProperties.mockResolvedValue([]);
    await renderWithProviders(<DashboardScreen />);

    expect(await screen.findByText('Add your first property')).toBeOnTheScreen();
    expect(screen.getByLabelText('Possible overcharge: R0.00')).toBeOnTheScreen();
    await fireEvent.press(screen.getByRole('button', { name: 'Add a property' }));
    expect(router.push).toHaveBeenCalledWith('/property/new');
  });

  it('shows totals, marks overdue deadlines and lists properties', async () => {
    mocked.getDashboard.mockResolvedValue({
      ...emptyDashboard,
      properties_count: 1,
      open_findings_count: 3,
      potential_overcharge_cents: 154000,
      active_disputes_count: 1,
      recovered_cents: 123456789,
      upcoming_deadlines: [
        {
          type: 'lodge_dispute',
          due_on: '2020-01-01',
          bill_id: 5,
          dispute_id: null,
          property_nickname: 'Sunset Court',
          label: 'Lodge a dispute for the 25 Sep 2026 bill',
        },
        {
          type: 'escalate',
          due_on: '2099-11-08',
          bill_id: 5,
          dispute_id: 4,
          property_nickname: 'Sunset Court',
          label: "Escalate if the municipality hasn't responded",
        },
      ],
    });
    mocked.listProperties.mockResolvedValue([
      {
        id: 3,
        nickname: 'Sunset Court',
        metro: 'johannesburg',
        account_number_masked: '••••4567',
        address: '12 Example Rd',
        property_type: 'sectional_title',
        bills_count: 4,
        open_findings_count: 2,
        created_at: '2026-10-09T05:17:14.000000Z',
      },
    ]);
    await renderWithProviders(<DashboardScreen />);

    expect(await screen.findByText('Hi, Thandi')).toBeOnTheScreen();
    expect(screen.getByLabelText('Possible overcharge: R1 540.00')).toBeOnTheScreen();
    expect(screen.getByLabelText('Recovered so far: R1 234 567.89')).toBeOnTheScreen();
    expect(screen.getByText('Overdue')).toBeOnTheScreen();
    expect(screen.getByText('2 open findings')).toBeOnTheScreen();

    await fireEvent.press(screen.getByRole('button', { name: /^Escalate if the municipality/ }));
    expect(router.push).toHaveBeenCalledWith({ pathname: '/dispute/[id]', params: { id: '4' } });
  });
});
