# Alef Frontend Style Kit

This folder contains the reusable frontend styling system extracted from the Alef Digital Solutions website.

## Files

- `alef-theme.css`
  The global Tailwind v4 theme tokens, font imports, base body styling, and base element resets.

- `alef-style-primitives.js`
  Reusable Tailwind class-string groups for layout, typography, surfaces, buttons, forms, alerts, and gradients.

## What styling system this uses

- Tailwind CSS v4
- theme tokens via `@theme`
- Google Fonts:
  - `Sora` for display headings
  - `Manrope` for body text

## Main brand tokens

- Orange: `#ff6600`
- Navy: `#002b5c`
- Ink: `#1f2933`
- Muted: `#5f6b76`
- Light: `#f5f7fa`

## Look and feel summary

- Clean white surfaces with soft blue-grey borders
- Large rounded corners such as `rounded-[2rem]`
- Strong navy/orange contrast
- Radial + linear gradient backgrounds for depth
- Heavy display typography for headings
- Soft shadows instead of harsh elevation

## How to use in another React project

1. Install Tailwind CSS v4.
2. Copy `alef-theme.css` into your project, or merge it into your main CSS entry file.
3. Import that CSS in your app entry.
4. Copy `alef-style-primitives.js` into your project.
5. Import the class groups you want, for example:

```js
import { layout, surfaces, buttons, forms } from './alef-style-primitives';
```

Example:

```jsx
<section className={layout.pageBackground}>
  <div className={layout.container}>
    <div className={surfaces.pagePanel}>
      <h1 className={typography.pageTitle}>Hello</h1>
      <button className={buttons.primary}>Continue</button>
    </div>
  </div>
</section>
```

## Suggested next step

If you want, the next useful move would be turning this into a full starter kit with:

- reusable React UI components
- a sample dashboard shell
- hero/banner sections
- styled cards, forms, and tables
