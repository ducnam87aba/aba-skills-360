import React from 'react';

/* ------------------------------------------------------------------ */
/*  Button — chunky, "pressable" 3D surface (ABA design language)      */
/* ------------------------------------------------------------------ */

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'blue' | 'purple' | 'amber' | 'rose' | 'outline' | 'ghost';
  size?: 'sm' | 'md' | 'lg';
  block?: boolean;
}

const BUTTON_VARIANTS: Record<NonNullable<ButtonProps['variant']>, { cls: string; edge: string }> = {
  primary: { cls: 'bg-brand-500 hover:bg-brand-400 text-white', edge: '#036c4a' },
  blue: { cls: 'bg-co hover:brightness-110 text-white', edge: '#0d8ec7' },
  purple: { cls: 'bg-sa hover:brightness-110 text-white', edge: '#5b3fd6' },
  amber: { cls: 'bg-sm hover:brightness-110 text-white', edge: '#d98c00' },
  rose: { cls: 'bg-er hover:brightness-110 text-white', edge: '#d63f54' },
  outline: { cls: 'bg-white text-brand-600 border-2 border-brand-200 hover:bg-brand-50', edge: '#cfeee0' },
  ghost: { cls: 'bg-transparent text-slate-500 hover:bg-slate-100', edge: 'transparent' },
};

const BUTTON_SIZES = {
  sm: 'px-3.5 py-2 text-xs rounded-xl',
  md: 'px-5 py-3 text-sm rounded-xl',
  lg: 'px-6 py-4 text-base rounded-2xl',
};

export function Button({
  children,
  className = '',
  variant = 'primary',
  size = 'md',
  block = false,
  style,
  ...props
}: ButtonProps) {
  const v = BUTTON_VARIANTS[variant];
  const isFlat = variant === 'outline' || variant === 'ghost';
  return (
    <button
      className={`pressable inline-flex items-center justify-center gap-2 font-extrabold uppercase tracking-wide transition-colors focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-200 disabled:cursor-not-allowed ${v.cls} ${BUTTON_SIZES[size]} ${block ? 'w-full' : ''} ${isFlat ? '' : 'pressable'} ${className}`}
      style={{ ...(style as React.CSSProperties), ['--press-edge' as any]: isFlat ? 'transparent' : v.edge }}
      {...props}
    >
      {children}
    </button>
  );
}

/* ------------------------------------------------------------------ */
/*  Card                                                               */
/* ------------------------------------------------------------------ */

export interface CardProps extends React.HTMLAttributes<HTMLDivElement> {
  hover?: boolean;
}

export function Card({ children, className = '', hover = false, ...props }: CardProps) {
  return (
    <div
      className={`bg-white rounded-2xl border border-black/5 shadow-card ${hover ? 'transition-all duration-200 hover:shadow-card-hover hover:-translate-y-0.5' : ''} ${className}`}
      {...props}
    >
      {children}
    </div>
  );
}

/* ------------------------------------------------------------------ */
/*  Badge                                                              */
/* ------------------------------------------------------------------ */

export interface BadgeProps extends React.HTMLAttributes<HTMLSpanElement> {
  variant?: 'default' | 'success' | 'warning' | 'purple' | 'blue' | 'rose';
}

const BADGE_VARIANTS: Record<NonNullable<BadgeProps['variant']>, string> = {
  default: 'bg-slate-100 text-slate-600',
  success: 'bg-brand-100 text-brand-700',
  warning: 'bg-amber-100 text-amber-700',
  purple: 'bg-violet-100 text-violet-700',
  blue: 'bg-sky-100 text-sky-700',
  rose: 'bg-rose-100 text-rose-700',
};

export function Badge({ children, className = '', variant = 'default', ...props }: BadgeProps) {
  return (
    <span
      className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-extrabold uppercase tracking-wide ${BADGE_VARIANTS[variant]} ${className}`}
      {...props}
    >
      {children}
    </span>
  );
}

/* ------------------------------------------------------------------ */
/*  ProgressBar — glossy rounded track with animated fill              */
/* ------------------------------------------------------------------ */

export interface ProgressBarProps {
  value: number;
  max?: number;
  color?: string;
  height?: number;
  className?: string;
}

export function ProgressBar({ value, max = 100, color = '#0aa96f', height = 12, className = '' }: ProgressBarProps) {
  const pct = Math.max(0, Math.min(100, (value / max) * 100));
  return (
    <div
      className={`w-full rounded-full bg-slate-100 overflow-hidden ${className}`}
      style={{ height }}
      role="progressbar"
      aria-valuenow={value}
      aria-valuemax={max}
    >
      <div
        className="relative h-full rounded-full transition-[width] duration-700 ease-out"
        style={{ width: `${pct}%`, backgroundColor: color }}
      >
        <span className="absolute inset-x-0 top-0 h-1/2 rounded-t-full bg-white/30" />
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------ */
/*  SkillRing — circular competency gauge                             */
/* ------------------------------------------------------------------ */

export interface SkillRingProps {
  value: number;
  max?: number;
  color?: string;
  size?: number;
  stroke?: number;
  label?: React.ReactNode;
  sublabel?: React.ReactNode;
}

export function SkillRing({
  value,
  max = 100,
  color = '#0aa96f',
  size = 96,
  stroke = 10,
  label,
  sublabel,
}: SkillRingProps) {
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const pct = Math.max(0, Math.min(1, value / max));
  const offset = c * (1 - pct);
  return (
    <div className="relative inline-flex items-center justify-center" style={{ width: size, height: size }}>
      <svg width={size} height={size} className="-rotate-90">
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="#eef2f0" strokeWidth={stroke} />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          stroke={color}
          strokeWidth={stroke}
          strokeLinecap="round"
          strokeDasharray={c}
          strokeDashoffset={offset}
          style={{ transition: 'stroke-dashoffset 0.8s cubic-bezier(0.22,1,0.36,1)' }}
        />
      </svg>
      <div className="absolute flex flex-col items-center leading-none">
        <span className="text-xl font-black text-slate-800">{label ?? Math.round(value)}</span>
        {sublabel ? <span className="text-[10px] font-bold text-slate-400 mt-0.5">{sublabel}</span> : null}
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------ */
/*  StreakCalendar — last-7-days activity dots                        */
/* ------------------------------------------------------------------ */

export interface StreakDay {
  label: string;
  active: boolean;
}

export function StreakCalendar({ days }: { days: StreakDay[] }) {
  return (
    <div className="flex items-center justify-between gap-1.5">
      {days.map((d, i) => (
        <div key={i} className="flex flex-col items-center gap-1 flex-1">
          <div
            className={`w-full aspect-square max-w-[34px] rounded-xl flex items-center justify-center text-sm font-black transition ${
              d.active ? 'bg-sm text-white shadow-[0_3px_0_0_#d98c00]' : 'bg-slate-100 text-slate-300'
            }`}
            title={d.label}
          >
            {d.active ? '🔥' : '·'}
          </div>
          <span className="text-[10px] font-bold text-slate-400">{d.label}</span>
        </div>
      ))}
    </div>
  );
}

/* ------------------------------------------------------------------ */
/*  StatPill — compact colored metric (streak / XP / gems …)           */
/* ------------------------------------------------------------------ */

export interface StatPillProps {
  icon: React.ReactNode;
  value: React.ReactNode;
  color: string;
  title?: string;
}

export function StatPill({ icon, value, color, title }: StatPillProps) {
  return (
    <div className="flex items-center gap-1.5 font-black" style={{ color }} title={title}>
      <span className="text-base leading-none">{icon}</span>
      <span className="tabular-nums">{value}</span>
    </div>
  );
}

/* ------------------------------------------------------------------ */
/*  SectionHeader                                                      */
/* ------------------------------------------------------------------ */

export interface SectionHeaderProps {
  eyebrow?: React.ReactNode;
  title: React.ReactNode;
  description?: React.ReactNode;
  action?: React.ReactNode;
  className?: string;
}

export function SectionHeader({ eyebrow, title, description, action, className = '' }: SectionHeaderProps) {
  return (
    <div className={`flex items-start justify-between gap-4 ${className}`}>
      <div>
        {eyebrow ? <div className="text-[11px] font-extrabold uppercase tracking-widest text-brand-500 mb-1">{eyebrow}</div> : null}
        <h2 className="text-xl font-black tracking-tight text-slate-800">{title}</h2>
        {description ? <p className="text-sm text-slate-500 mt-1 max-w-prose">{description}</p> : null}
      </div>
      {action ? <div className="shrink-0">{action}</div> : null}
    </div>
  );
}
