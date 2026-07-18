import React from "react";

export interface InputProps extends Omit<React.InputHTMLAttributes<HTMLInputElement>, "style"> {
  /** Field label rendered above the box. */
  label?: string;
  /** Helper / hint text below the box. Turns red when `error`. */
  helper?: string;
  /** Error state — red border + red helper. */
  error?: boolean;
  /** Optional icon node inside the box, before the text. */
  leadingIcon?: React.ReactNode;
  style?: React.CSSProperties;
}

/**
 * Labelled text input — the source InputField.
 * @startingPoint section="Core" subtitle="Labelled input with helper & error states" viewport="700x180"
 */
export function Input(props: InputProps): JSX.Element;
