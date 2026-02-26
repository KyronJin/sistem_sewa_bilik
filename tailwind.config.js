/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./*.php",
    "./*.html",
    "./**/*.php",
    "./**/*.html"
  ],
  safelist: [
    'swal2-popup', 'swal2-title', 'swal2-content', 'swal2-actions', 'swal2-confirm', 'swal2-cancel'
  ],
  theme: {
    extend: {},
  },
  plugins: [],
}