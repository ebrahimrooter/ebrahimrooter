// Builds server/acc/vendor/app.css from the classes used in the accounting UI.
//   cd tools/acc-css && npm install && npm run build
module.exports = {
  content: ['../../server/acc/index.html', '../../server/acc/js/*.js'],
  theme: { extend: {
    fontFamily: { sans: ['Vazirmatn', 'Tahoma', 'sans-serif'] },
    colors: {
      primary: { 50: '#eff6ff', 100: '#dbeafe', 500: '#3b82f6', 600: '#2563eb', 700: '#1d4ed8', 800: '#1e40af' },
      accent: { 500: '#10b981', 600: '#059669' }
    }
  } }
};
