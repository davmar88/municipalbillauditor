import { fireEvent, screen } from '@testing-library/react-native';

import { ApiError } from '@/api';
import { makeAuth, renderWithProviders } from '@/test/render';

import { SignUpScreen } from '../SignUpScreen';

jest.mock('expo-router', () => ({
  router: { push: jest.fn(), replace: jest.fn(), back: jest.fn(), canGoBack: jest.fn(() => true) },
}));

async function fillInForm() {
  await fireEvent.changeText(screen.getByLabelText('Your name'), 'Thandi M');
  await fireEvent.changeText(screen.getByLabelText('Email'), 'thandi@example.com');
  await fireEvent.changeText(screen.getByLabelText('Password'), 'correct horse');
  await fireEvent.changeText(screen.getByLabelText('Confirm password'), 'correct horse');
}

describe('SignUpScreen', () => {
  it('requires POPIA consent before creating an account', async () => {
    const auth = makeAuth();
    await renderWithProviders(<SignUpScreen />, { auth });

    await fillInForm();
    await fireEvent.press(screen.getByRole('button', { name: 'Create account' }));

    expect(auth.signUp).not.toHaveBeenCalled();
    expect(screen.getByText(/Please tick this box to continue/)).toBeOnTheScreen();
    expect(
      screen.getByRole('checkbox', { name: 'I agree that my information may be used this way' }),
    ).not.toBeChecked();
  });

  it('creates the account once consent is given, with AI reading off by default', async () => {
    const auth = makeAuth();
    await renderWithProviders(<SignUpScreen />, { auth });

    await fillInForm();
    await fireEvent.press(screen.getByRole('checkbox', { name: 'I agree that my information may be used this way' }));
    await fireEvent.press(screen.getByRole('button', { name: 'Create account' }));

    expect(auth.signUp).toHaveBeenCalledWith({
      name: 'Thandi M',
      email: 'thandi@example.com',
      password: 'correct horse',
      password_confirmation: 'correct horse',
      popia_consent: true,
      ai_extraction_consent: false,
    });
    expect(screen.queryByText(/Please tick this box/)).toBeNull();
  });

  it('explains that AI reading sends the bill outside South Africa and sends the opt-in', async () => {
    const auth = makeAuth();
    await renderWithProviders(<SignUpScreen />, { auth });

    expect(screen.getByText(/AI provider outside South Africa/)).toBeOnTheScreen();
    await fillInForm();
    await fireEvent.press(screen.getByRole('checkbox', { name: 'I agree that my information may be used this way' }));
    await fireEvent.press(screen.getByRole('checkbox', { name: 'Read my bills with AI' }));
    await fireEvent.press(screen.getByRole('button', { name: 'Create account' }));

    expect(auth.signUp).toHaveBeenCalledWith(expect.objectContaining({ ai_extraction_consent: true }));
  });

  it('shows server validation errors next to the right field', async () => {
    const auth = makeAuth({
      signUp: jest.fn(() =>
        Promise.reject(
          new ApiError(422, 'The email has already been taken.', { email: ['The email has already been taken.'] }),
        ),
      ),
    });
    await renderWithProviders(<SignUpScreen />, { auth });

    await fillInForm();
    await fireEvent.press(screen.getByRole('checkbox', { name: 'I agree that my information may be used this way' }));
    await fireEvent.press(screen.getByRole('button', { name: 'Create account' }));

    expect(await screen.findByText('The email has already been taken.')).toBeOnTheScreen();
    expect(screen.getByText('Please check the highlighted fields.')).toBeOnTheScreen();
  });

  it('checks the password rules before calling the server', async () => {
    const auth = makeAuth();
    await renderWithProviders(<SignUpScreen />, { auth });

    await fireEvent.changeText(screen.getByLabelText('Password'), 'short');
    await fireEvent.changeText(screen.getByLabelText('Confirm password'), 'different');
    await fireEvent.press(screen.getByRole('button', { name: 'Create account' }));

    expect(screen.getByText('Use at least 8 characters.')).toBeOnTheScreen();
    expect(screen.getByText("The passwords don't match.")).toBeOnTheScreen();
    expect(screen.getByText('Enter your name.')).toBeOnTheScreen();
    expect(auth.signUp).not.toHaveBeenCalled();
  });
});
