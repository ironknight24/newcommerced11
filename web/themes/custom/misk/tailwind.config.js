/** @type {import('tailwindcss').Config} */
const path = require('path');

module.exports = {
  // Resolve from this file so `npm run build` works regardless of cwd.
  content: [
    path.join(__dirname, 'templates/**/*.twig'),
    path.join(__dirname, 'js/**/*.js'),
    path.join(__dirname, 'src/**/*.css'),
    // From web/themes/custom/misk → web/modules/custom/court_booking
    path.join(__dirname, '../../../modules/custom/court_booking/templates/**/*.twig'),
    path.join(__dirname, '../../../modules/custom/court_booking/js/**/*.js'),
    // PHP may emit class names (field formatters, #markup); scan for completeness.
    path.join(__dirname, '../../../modules/custom/court_booking/**/*.php'),
    path.join(__dirname, '../../../modules/custom/court_booking/court_booking.module'),
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['DM Sans', 'system-ui', 'sans-serif'],
        display: ['Outfit', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
