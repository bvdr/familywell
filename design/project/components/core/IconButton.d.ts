import React from "react";

export interface IconButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  /** The icon node to render (Lucide/Phosphor SVG). */
  icon: React.ReactNode;
  /** subtle = accent-subtle well, surface = bordered white, solid = accent, ghost = transparent. */
  variant?: "subtle" | "surface" | "solid" | "ghost";
  /** circle (default) or rounded square. */
  shape?: "circle" | "square";
  /** Pixel size of the square hit area. Default 40. */
  size?: number;
  /** Accessible label — required since there's no text. */
  ariaLabel?: string;
}

export function IconButton(props: IconButtonProps): JSX.Element;
