// Tailwind CSS v3 configuration for Urban Escape.
// assets/tailwind.css is committed, so the site needs no build step. To rebuild it after adding
// new utility classes (requires Node.js):
//   npx tailwindcss@3.4.16 -i assets/src/tailwind.input.css -o assets/tailwind.css --minify
module.exports = {
  content: ['./**/*.php', './assets/app.js', '!./tests/**', '!./vendor/**'],
  theme: {
    extend: {
      colors: {
        primary: '#1E3A5F',
        secondary: '#14283F',
        accent: '#D89B32',
        success: '#198754',
        warning: '#D99A00',
        danger: '#DC3545',
      },
    },
  },
};
