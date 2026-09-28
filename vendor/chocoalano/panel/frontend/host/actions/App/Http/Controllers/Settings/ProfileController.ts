/**
 * Wayfinder's controller module for the starter kit's profile settings.
 *
 * The panel's profile page binds `ProfileController.update.form()` onto an
 * Inertia `<Form>`, which is the whole of the surface used here.
 */
import type { RouteDefinition } from '../../../../../routes/shape';
import { definition } from '../../../../../routes/shape';

const ProfileController: { update: RouteDefinition; destroy: RouteDefinition } =
    {
        update: definition('/settings/profile', 'patch'),
        destroy: definition('/settings/profile', 'delete'),
    };

export default ProfileController;
