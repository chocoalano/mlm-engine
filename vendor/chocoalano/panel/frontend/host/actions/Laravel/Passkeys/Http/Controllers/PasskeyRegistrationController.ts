import type { RouteDefinition } from '../../../../../routes/shape';
import { definition } from '../../../../../routes/shape';

export const store: RouteDefinition = definition('/passkeys', 'post');
export const destroy: RouteDefinition = definition('/passkeys', 'delete');
