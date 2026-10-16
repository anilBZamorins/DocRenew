/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./src/**/*.{html,ts}'],
  darkMode: ['class', '[data-theme="dark"]'],
  theme: {
    extend: {
      // Maps the DocRenew design tokens (docrenew_mockups.html) onto
      // Tailwind's palette as CSS-variable-backed colors, so dark mode
      // keeps working via the same :root variable swap instead of
      // Tailwind's own dark: variant needing a parallel color set.
      colors: {
        bg: 'var(--bg)',
        surface: 'var(--surface)',
        'surface-2': 'var(--surface-2)',
        line: 'var(--line)',
        ink: 'var(--ink)',
        muted: 'var(--muted)',
        navy: 'var(--navy)',
        brand: {
          blue: 'var(--blue)',
          teal: 'var(--teal)',
          sky: 'var(--sky)',
        },
        ok: { DEFAULT: 'var(--ok)', bg: 'var(--ok-bg)' },
        warn: { DEFAULT: 'var(--warn)', bg: 'var(--warn-bg)' },
        urgent: { DEFAULT: 'var(--urgent)', bg: 'var(--urgent-bg)' },
        crit: { DEFAULT: 'var(--crit)', bg: 'var(--crit-bg)' },
        idle: { DEFAULT: 'var(--idle)', bg: 'var(--idle-bg)' },
      },
      fontFamily: {
        sans: ['Inter', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'Helvetica', 'Arial', 'sans-serif'],
        display: ['Manrope', 'Inter', 'sans-serif'],
      },
      borderRadius: {
        card: '14px',
        pill: '999px',
      },
    },
  },
  plugins: [],
};
