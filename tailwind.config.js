const colors = require('tailwindcss/colors');
export default {
  content: [
        './resources/**/*.antlers.html',
        './resources/**/*.blade.php',
        './content/**/*.md'
    ],
    theme: {
        fontFamily: {
            sans: ['Inter', 'sans'],
            mono: ['Menlo', 'monospace']
        },
        extend: {
            colors: {
                'teal': '#008483',
                // For teal text sitting on the tinted teal-light panels. Plain `teal`
                // only reaches 3.9:1 against that background, under the 4.5:1 WCAG AA
                // threshold; this clears it at 5.1:1.
                'teal-dark': '#00706e',
                'teal-light': '#a6d0cf',
                'grey': colors.gray,
                // Dessert People house colours, carried over from the old sub-site: a
                // dark brick red for headings and the progress bar, gold for the date on
                // the banner. Only the dessert-people partials use them.
                'brick': '#7f1d1d',
                'gold': '#d0aa48',
            }
        }
    },
    plugins: [],
    important: true
}
