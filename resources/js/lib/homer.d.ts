// Types for the synced lib/homer.js (the <homer-mascot> renderer). Only what the app uses.
export type HomerReaction = 'hello' | 'success' | 'celebrate' | 'error' | 'notfound' | 'yes' | 'no' | 'thinking' | 'surprise' | 'wink' | 'bye';
export type HomerAction = 'hop' | 'spin' | 'nod' | 'no' | 'wave' | 'walk' | 'tip' | 'dance' | 'look' | 'stretch';
export const react: (name: HomerReaction) => void;
