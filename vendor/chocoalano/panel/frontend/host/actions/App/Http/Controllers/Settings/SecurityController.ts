import type { RouteDefinition } from '../../../../../routes/shape';
import { definition } from '../../../../../routes/shape';

const SecurityController: { update: RouteDefinition } = {
    update: definition('/settings/security', 'put'),
};

export default SecurityController;
