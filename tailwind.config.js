/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './public/**/*.php',
    './public/**/*.html',
    './src/**/*.php',
    './src/**/*.html',
    './*.php',
    './*.html'
  ],
  darkMode: 'class',
  theme: {
    extend: {
      fontFamily: {
        sans: ['Poppins', 'sans-serif'],
      },
    },
  },
  plugins: [],
}

