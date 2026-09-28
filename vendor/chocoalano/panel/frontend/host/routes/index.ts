/**
 * Wayfinder's root route module — `@/routes`.
 *
 * A generated module in a real application. The shape is Wayfinder's: a call
 * returns something `<Link :href>` and `<Form v-bind>` both accept, and the
 * helpers hang off it.
 */
import type { RouteDefinition } from './shape';
import { definition } from './shape';

export const logout: RouteDefinition = definition('/logout', 'post');
