import { StyleSheet, Text, type TextProps } from 'react-native';

import { colors, fontSize } from '@/theme';

export type TextVariant = 'hero' | 'title' | 'heading' | 'body' | 'strong' | 'muted' | 'small' | 'label';

export function AppText({ variant = 'body', style, ...rest }: TextProps & { variant?: TextVariant }) {
  return <Text {...rest} style={[styles.base, styles[variant], style]} />;
}

const styles = StyleSheet.create({
  base: { color: colors.text, fontSize: fontSize.body, lineHeight: 23 },
  hero: { fontSize: fontSize.hero, lineHeight: 34, fontWeight: '700' },
  title: { fontSize: fontSize.title, lineHeight: 28, fontWeight: '700' },
  heading: { fontSize: fontSize.large, lineHeight: 24, fontWeight: '600' },
  body: {},
  strong: { fontWeight: '600' },
  muted: { color: colors.textMuted },
  small: { fontSize: fontSize.small, lineHeight: 20, color: colors.textMuted },
  label: { fontSize: fontSize.small, lineHeight: 20, fontWeight: '600', color: colors.text },
});
