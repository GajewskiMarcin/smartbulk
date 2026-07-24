/** @type {import('tailwindcss').Config} */
// Scoped under .smartbulk-scope so we don't collide with PrestaShop admin styles.
//
// Border-radius + spacing are given in explicit pixels (not rem) because PS admin
// sets html font-size to something other than 16px, which would make rem-based
// Tailwind defaults render smaller than the designs (e.g. rounded-lg = 7px instead of 8px).

export default {
  important: '.smartbulk-scope',
  content: ['./src/**/*.{ts,tsx}'],
  theme: {
    borderRadius: {
      none:    '0',
      sm:      '4px',
      DEFAULT: '6px',
      md:      '6px',
      lg:      '8px',
      xl:      '12px',
      '2xl':   '16px',
      '3xl':   '24px',
      full:    '9999px',
    },
    extend: {
      colors: {
        primary: {
          DEFAULT: '#7c3aed',
          foreground: '#ffffff',
          50:  '#faf5ff',
          100: '#f3e8ff',
          600: '#7c3aed',
          700: '#6d28d9',
        },
        muted: {
          DEFAULT: '#f8fafc',
          foreground: '#64748b',
        },
        border: '#e2e8f0',
        destructive: {
          DEFAULT: '#dc2626',
          foreground: '#ffffff',
        },
        success: {
          DEFAULT: '#16a34a',
          foreground: '#ffffff',
        },
        warning: {
          DEFAULT: '#d97706',
          foreground: '#ffffff',
        },
      },
    },
  },
  plugins: [],
};
