import React from "react";

export interface TabBarItem {
  id: string;
  label: string;
  /** Icon node (Lucide SVG sized ~22px). */
  icon: React.ReactNode;
}

export interface TabBarProps {
  items: TabBarItem[];
  /** id of the active tab. */
  activeId?: string;
  onSelect?: (id: string) => void;
  style?: React.CSSProperties;
}

/**
 * Floating translucent bottom tab bar for the mobile app.
 * @startingPoint section="Navigation" subtitle="Mobile floating tab bar" viewport="420x120"
 */
export function TabBar(props: TabBarProps): JSX.Element;
