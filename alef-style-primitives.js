export const layout = {
  container: 'mx-auto w-[min(92%,72rem)]',
  pageBackground:
    'min-h-screen bg-[radial-gradient(circle_at_top,_rgba(255,102,0,0.08),_transparent_25rem),linear-gradient(180deg,_#ffffff_0%,_#f8fbff_100%)]',
};

export const typography = {
  sectionKicker: 'text-sm font-extrabold uppercase tracking-[0.28em] text-brand-orange',
  pageTitle: 'mt-4 font-display text-3xl font-black tracking-tight text-brand-navy sm:text-4xl lg:text-5xl',
  panelTitle: 'font-display text-2xl font-black tracking-tight text-brand-navy',
  body: 'text-base leading-8 text-slate-600',
};

export const surfaces = {
  pagePanel: 'rounded-[2rem] border border-slate-200 bg-white p-6 shadow-[0_24px_70px_rgba(15,23,42,0.06)] sm:p-8',
  authPanel: 'w-full max-w-2xl rounded-[2rem] border border-slate-200 bg-white p-8 shadow-[0_30px_80px_rgba(15,23,42,0.08)] sm:p-10',
  legalPanel: 'rounded-[2rem] border border-slate-200 bg-white p-8 shadow-[0_18px_45px_rgba(0,43,92,0.06)] sm:p-10',
  card: 'rounded-[1.5rem] border border-slate-200 bg-slate-50 px-5 py-4',
  softCard: 'rounded-[1.5rem] border border-slate-200 bg-slate-50 px-4 py-4',
  centeredCard: 'mx-auto max-w-3xl rounded-[2rem] border border-slate-200 bg-white px-8 py-14 text-center shadow-[0_18px_45px_rgba(0,43,92,0.06)]',
};

export const gradients = {
  hero: 'relative overflow-hidden bg-[radial-gradient(circle_at_top_right,rgba(255,102,0,0.16),transparent_24rem),linear-gradient(135deg,#002b5c,#001c3d)] py-20 text-white sm:py-24',
  cta: 'relative grid gap-10 overflow-hidden rounded-[2rem] bg-[radial-gradient(circle_at_bottom_right,rgba(255,102,0,0.18),transparent_18rem),linear-gradient(135deg,#002b5c,#001c3d)] px-7 py-12 text-white shadow-[0_30px_80px_rgba(0,43,92,0.22)] sm:px-10 lg:grid-cols-[1.2fr_0.8fr] lg:px-16 lg:py-16',
  featureCard: 'flex min-h-[28rem] flex-col justify-between overflow-hidden rounded-[2rem] bg-[radial-gradient(circle_at_top_right,rgba(255,102,0,0.18),transparent_16rem),linear-gradient(135deg,#002b5c,#001c3d)] p-8 text-white shadow-[0_28px_70px_rgba(0,43,92,0.22)]',
};

export const buttons = {
  primary:
    'inline-flex items-center gap-2 rounded-full bg-brand-orange px-6 py-3 text-sm font-bold text-white transition duration-200 hover:-translate-y-0.5 hover:bg-orange-500',
  secondary:
    'inline-flex items-center gap-2 rounded-full bg-brand-navy px-6 py-3 text-sm font-bold text-white transition duration-200 hover:-translate-y-0.5 hover:bg-[#001c3d]',
  outlineLight:
    'inline-flex items-center gap-2 rounded-full border border-white/35 px-6 py-3 text-sm font-bold text-white transition duration-200 hover:-translate-y-0.5 hover:border-white hover:bg-white/10',
  ghost:
    'inline-flex items-center gap-2 rounded-full border border-brand-navy/20 px-6 py-3 text-sm font-bold text-brand-navy transition duration-200 hover:-translate-y-0.5 hover:border-brand-orange hover:text-brand-orange',
  linkTile:
    'rounded-2xl border border-slate-200 px-4 py-3 font-semibold text-slate-700 transition-colors duration-200 hover:border-brand-orange hover:text-brand-orange',
};

export const forms = {
  input:
    'w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-brand-orange focus:bg-white',
  inputLarge:
    'w-full rounded-3xl border border-slate-200 bg-slate-50 px-5 py-4 text-base text-slate-900 outline-none transition focus:border-brand-orange focus:bg-white',
  textarea:
    'w-full rounded-[1.5rem] border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-7 outline-none transition focus:border-brand-orange focus:bg-white',
  search:
    'w-full rounded-full border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-brand-orange focus:bg-white',
};

export const alerts = {
  success: 'rounded-[1.75rem] border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-700',
  error: 'rounded-[1.75rem] border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-700',
  warning: 'rounded-3xl border border-orange-200 bg-orange-50 px-5 py-4 text-sm text-orange-900',
  neutral: 'rounded-3xl border border-slate-200 bg-slate-50 px-5 py-4 text-sm text-slate-700',
};

export const badges = {
  neutral: 'rounded-full bg-slate-100 px-4 py-2 text-xs font-bold uppercase tracking-[0.22em] text-slate-500',
  current: 'rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.18em] text-emerald-700',
};
