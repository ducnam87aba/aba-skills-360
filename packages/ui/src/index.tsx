import React from 'react';

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'outline' | 'ghost' | 'success';
  size?: 'sm' | 'md' | 'lg';
}

export function Button({
  children,
  className = '',
  variant = 'primary',
  size = 'md',
  ...props
}: ButtonProps) {
  const base =
    'inline-flex items-center justify-center font-semibold transition-all duration-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50 disabled:pointer-events-none active:scale-95';

  const variants = {
    primary:
      'bg-emerald-600 hover:bg-emerald-500 text-white shadow-md shadow-emerald-500/20 focus:ring-emerald-500',
    secondary:
      'bg-indigo-600 hover:bg-indigo-500 text-white shadow-md shadow-indigo-500/20 focus:ring-indigo-500',
    outline:
      'border-2 border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700 bg-transparent text-slate-800 dark:text-slate-100',
    ghost:
      'bg-transparent hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200',
    success:
      'bg-green-600 hover:bg-green-500 text-white shadow-md shadow-green-500/20',
  };

  const sizes = {
    sm: 'px-3 py-1.5 text-xs',
    md: 'px-5 py-2.5 text-sm',
    lg: 'px-6 py-3.5 text-base',
  };

  return (
    <button
      className={`${base} ${variants[variant]} ${sizes[size]} ${className}`}
      {...props}
    >
      {children}
    </button>
  );
}

export interface CardProps extends React.HTMLAttributes<HTMLDivElement> {
  glass?: boolean;
}

export function Card({
  children,
  className = '',
  glass = false,
  ...props
}: CardProps) {
  const base = glass
    ? 'backdrop-blur-md bg-white/70 dark:bg-slate-900/70 border border-white/20 dark:border-slate-800/80 shadow-xl rounded-2xl'
    : 'bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 shadow-sm rounded-2xl';

  return (
    <div className={`${base} ${className}`} {...props}>
      {children}
    </div>
  );
}

export interface BadgeProps extends React.HTMLAttributes<HTMLSpanElement> {
  variant?: 'default' | 'success' | 'warning' | 'purple' | 'blue';
}

export function Badge({
  children,
  className = '',
  variant = 'default',
  ...props
}: BadgeProps) {
  const variants = {
    default: 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
    success:
      'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-400 border border-emerald-200/50 dark:border-emerald-800/50',
    warning:
      'bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-400 border border-amber-200/50 dark:border-amber-800/50',
    purple:
      'bg-purple-50 dark:bg-purple-950/40 text-purple-700 dark:text-purple-400 border border-purple-200/50 dark:border-purple-800/50',
    blue:
      'bg-sky-50 dark:bg-sky-950/40 text-sky-700 dark:text-sky-400 border border-sky-200/50 dark:border-sky-800/50',
  };

  return (
    <span
      className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold tracking-wide ${variants[variant]} ${className}`}
      {...props}
    >
      {children}
    </span>
  );
}
