import React from "react";

export interface BadgeProps extends React.HTMLAttributes<HTMLSpanElement> {
  /** subtle (green, default), solid, warning, error, neutral, outline. */
  variant?: "subtle" | "solid" | "warning" | "error" | "neutral" | "outline";
  /** Show a leading status dot. */
  dot?: boolean;
  children?: React.ReactNode;
}

export function Badge(props: BadgeProps): JSX.Element;
