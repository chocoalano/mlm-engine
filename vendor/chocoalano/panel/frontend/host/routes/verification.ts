import type { RouteDefinition } from './shape';
import { definition } from './shape';

export const send: RouteDefinition = definition(
    '/email/verification-notification',
    'post',
);
