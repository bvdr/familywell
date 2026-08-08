import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';
import { Fragment } from 'react';

export function Breadcrumbs({ breadcrumbs }: { breadcrumbs: BreadcrumbItemType[] }) {
    return (
        <>
            {breadcrumbs.length > 0 && (
                <Breadcrumb className="min-w-0 flex-1">
                    {/* nowrap + min-w-0 so a long current-page title truncates with … instead of
                        wrapping into a second line and overflowing the fixed-height header. */}
                    <BreadcrumbList className="min-w-0 flex-nowrap">
                        {breadcrumbs.map((item, index) => {
                            const isLast = index === breadcrumbs.length - 1;
                            return (
                                <Fragment key={index}>
                                    {/* Only the last crumb shrinks/truncates; parents keep their full label. */}
                                    <BreadcrumbItem className={isLast ? 'min-w-0 flex-1' : 'shrink-0'}>
                                        {isLast ? (
                                            <BreadcrumbPage className="block truncate">{item.title}</BreadcrumbPage>
                                        ) : (
                                            <BreadcrumbLink href={item.href}>{item.title}</BreadcrumbLink>
                                        )}
                                    </BreadcrumbItem>
                                    {!isLast && <BreadcrumbSeparator className="shrink-0" />}
                                </Fragment>
                            );
                        })}
                    </BreadcrumbList>
                </Breadcrumb>
            )}
        </>
    );
}
