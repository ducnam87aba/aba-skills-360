/** @type {import('tailwindcss').Config} */
module.exports = {
  darkMode: 'class',
  content: [
    './src/**/*.{js,ts,jsx,tsx,mdx}',
    '../../packages/ui/src/**/*.{js,ts,jsx,tsx}',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['var(--font-nunito)', 'system-ui', 'Segoe UI', 'sans-serif'],
      },
      colors: {
        // ABA primary — friendly green identity (its own design system, not a copy)
        brand: {
          50: '#eafbef',
          100: '#cdf5da',
          200: '#9dead0',
          300: '#5fdbb1',
          400: '#28c78c',
          500: '#0aa96f',
          600: '#028a5c',
          700: '#036c4a',
          800: '#06563c',
          900: '#073f2e',
          // solid edge color for the chunky "pressable" 3D buttons
          edge: '#036c4a',
        },
        // 6 core competencies — stable identity colors
        sa: '#7c5cff', // Tự nhận thức — violet
        er: '#ff5d73', // Quản lý cảm xúc — rose
        sm: '#ffae00', // Tự quản lý — amber
        co: '#1cb0f6', // Giao tiếp — sky
        ce: '#0aa96f', // Hợp tác & đồng cảm — green
        pd: '#4b6bff', // Giải quyết vấn đề — indigo
        ink: '#4b4b4b',
        canvas: '#f7fbf8',
      },
      boxShadow: {
        // Duolingo-style chunky bottom edge
        'pop': '0 4px 0 0 rgba(0,0,0,0.12)',
        'card': '0 2px 8px -2px rgba(13, 84, 55, 0.10), 0 1px 2px -1px rgba(13, 84, 55, 0.06)',
        'card-hover': '0 12px 24px -12px rgba(13, 84, 55, 0.25)',
      },
      keyframes: {
        'float-y': { '0%,100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-6px)' } },
        'pop-in': { '0%': { transform: 'scale(0.8)', opacity: '0' }, '60%': { transform: 'scale(1.06)' }, '100%': { transform: 'scale(1)', opacity: '1' } },
        'wiggle': { '0%,100%': { transform: 'rotate(-3deg)' }, '50%': { transform: 'rotate(3deg)' } },
        'sheen': { '0%': { transform: 'translateX(-120%)' }, '100%': { transform: 'translateX(220%)' } },
        'ring': { '0%,100%': { transform: 'scale(1)', opacity: '0.5' }, '50%': { transform: 'scale(1.35)', opacity: '0' } },
      },
      animation: {
        'float-y': 'float-y 3s ease-in-out infinite',
        'pop-in': 'pop-in 0.35s cubic-bezier(0.34,1.56,0.64,1) both',
        'wiggle': 'wiggle 0.5s ease-in-out infinite',
        'sheen': 'sheen 2.5s ease-in-out infinite',
        'ring': 'ring 1.8s ease-out infinite',
      },
    },
  },
  plugins: [],
};
