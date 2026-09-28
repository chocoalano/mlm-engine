import type { RouteDefinition } from './shape';
import { definition } from './shape';

export const enable: RouteDefinition = definition(
    '/user/two-factor-authentication',
    'post',
);
export const disable: RouteDefinition = definition(
    '/user/two-factor-authentication',
    'delete',
);
