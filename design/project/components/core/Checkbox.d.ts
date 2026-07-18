import React from "react";

export interface CheckboxProps {
  /** Checked state (controlled). */
  checked?: boolean;
  /** Called with the next boolean when toggled. */
  onChange?: (next: boolean) => void;
  disabled?: boolean;
  /** Box size in px. Default 22. */
  size?: number;
  /** Optional inline label to the right of the box. */
  label?: React.ReactNode;
  style?: React.CSSProperties;
}

export function Checkbox(props: CheckboxProps): JSX.Element;
