import type { RouteDefinition } from './shape';
import { definition } from './shape';

export const email: RouteDefinition = definition('/forgot-password', 'post');
export const update: RouteDefinition = definition('/reset-password', 'post');
