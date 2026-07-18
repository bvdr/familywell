import React from "react";

export type AvatarTint = "sage" | "blue" | "clay" | "lilac" | "accent" | "subtle";

export interface AvatarProps extends React.HTMLAttributes<HTMLSpanElement> {
  /** Initial(s) to show when there's no image, e.g. "S" or "MR". */
  initial?: string;
  /** Optional image URL. Overrides the initial. */
  src?: string | null;
  /** Background tint. Family members cycle sage/blue/clay/lilac. */
  tint?: AvatarTint;
  /** Diameter in px. Default 44. */
  size?: number;
}

export function Avatar(props: AvatarProps): JSX.Element;
