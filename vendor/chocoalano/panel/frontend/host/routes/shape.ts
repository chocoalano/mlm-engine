/**
 * What a Wayfinder route export looks like from the calling side.
 *
 * Only the parts the panel's own components use are modelled. Wayfinder's
 * real definitions carry more — query builders, per-method variants — and a
 * fuller stand-in here would be inventing a contract rather than recording
 * one.
 */
export interface RouteInvocation {
    url: string;
    method: 'get' | 'post' | 'put' | 'patch' | 'delete';
}

export interface RouteDefinition {
    (...args: Array<string | number | undefined>): RouteInvocation;
    url(...args: Array<string | number | undefined>): string;
    form(...args: Array<string | number | undefined>): RouteInvocation;
}

export function definition(
    url: string,
    method: RouteInvocation['method'] = 'get',
): RouteDefinition {
    const invoke = (): RouteInvocation => ({ url, method });

    return Object.assign(invoke, {
        url: (): string => url,
        form: invoke,
    }) as RouteDefinition;
}
