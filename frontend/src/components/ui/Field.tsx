import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react';

interface FieldWrapperProps {
  label?: string;
  hint?: string;
  error?: string;
  required?: boolean;
  children: ReactNode;
}

/** Label + hint/error wrapper shared by Input/Select/Textarea below. */
export function FieldWrapper({ label, hint, error, required, children }: FieldWrapperProps) {
  return (
    <div className="space-y-1">
      {label && (
        <label className="block text-xs font-medium text-ink-muted">
          {label}
          {required && <span className="text-danger"> *</span>}
        </label>
      )}
      {children}
      {error ? (
        <p className="text-xs text-danger">{error}</p>
      ) : hint ? (
        <p className="text-xs text-ink-faint">{hint}</p>
      ) : null}
    </div>
  );
}

const baseControlClass =
  'w-full rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-sm text-ink placeholder:text-ink-faint outline-none transition-colors focus:border-primary-500 focus:ring-1 focus:ring-primary-500 disabled:bg-canvas disabled:text-ink-faint';

interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  hint?: string;
  error?: string;
}

export function Input({ label, hint, error, className = '', ...rest }: InputProps) {
  return (
    <FieldWrapper label={label} hint={hint} error={error} required={rest.required}>
      <input className={`${baseControlClass} ${error ? 'border-danger' : ''} ${className}`} {...rest} />
    </FieldWrapper>
  );
}

interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  label?: string;
  hint?: string;
  error?: string;
}

export function Select({ label, hint, error, className = '', children, ...rest }: SelectProps) {
  return (
    <FieldWrapper label={label} hint={hint} error={error} required={rest.required}>
      <select className={`${baseControlClass} ${error ? 'border-danger' : ''} ${className}`} {...rest}>
        {children}
      </select>
    </FieldWrapper>
  );
}

interface TextAreaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  label?: string;
  hint?: string;
  error?: string;
}

export function TextArea({ label, hint, error, className = '', ...rest }: TextAreaProps) {
  return (
    <FieldWrapper label={label} hint={hint} error={error} required={rest.required}>
      <textarea className={`${baseControlClass} ${error ? 'border-danger' : ''} ${className}`} {...rest} />
    </FieldWrapper>
  );
}
