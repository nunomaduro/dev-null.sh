import {
    Combobox,
    ComboboxButton,
    ComboboxInput,
    ComboboxOption,
    ComboboxOptions,
} from '@headlessui/react';
import { Form } from '@inertiajs/react';
import type { TimeZone } from '@vvo/tzdb';
import { getTimeZones } from '@vvo/tzdb';
import { Check, ChevronsUpDown } from 'lucide-react';
import { useState, useSyncExternalStore } from 'react';
import UserTimezoneController from '@/actions/App/Http/Controllers/UserTimezoneController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { useUser } from '@/hooks/use-user';
import { browserTimeZone } from '@/lib/utils';

const timeZones = getTimeZones();

const subscribe = () => () => {};

function findTimeZone(name: string): TimeZone | undefined {
    return timeZones.find(
        (timeZone) => timeZone.name === name || timeZone.group.includes(name),
    );
}

function offset(timeZone: TimeZone): string {
    return `GMT${timeZone.currentTimeFormat.slice(0, 6)}`;
}

function describe(timeZone: TimeZone): string {
    return `${timeZone.alternativeName} - ${timeZone.mainCities.slice(0, 3).join(', ')}`;
}

function matches(timeZone: TimeZone, query: string): boolean {
    return [
        timeZone.name,
        timeZone.alternativeName,
        timeZone.countryName,
        timeZone.abbreviation,
        ...timeZone.group,
        ...timeZone.mainCities,
    ].some((value) => value.toLowerCase().includes(query));
}

export default function TimezonePicker() {
    const { user } = useUser();
    const [selected, setSelected] = useState<string | null>(user.timezone);
    const [query, setQuery] = useState('');
    const detected = useSyncExternalStore(
        subscribe,
        () => findTimeZone(browserTimeZone()),
        () => undefined,
    );

    const normalizedQuery = query.trim().toLowerCase();
    const filtered =
        normalizedQuery === ''
            ? timeZones
            : timeZones.filter((timeZone) =>
                  matches(timeZone, normalizedQuery),
              );

    return (
        <section className="px-4 py-6 sm:px-6">
            <Heading
                title="Timezone"
                description="Hover a commit or reply time to see it in your timezone, and in the author's when they have set one. Others see yours the same way. Leave it unset to use your browser's timezone without sharing it."
            />

            <Form
                {...UserTimezoneController.update.form()}
                options={{ preserveScroll: true }}
            >
                {({ processing, errors }) => (
                    <>
                        <input
                            type="hidden"
                            name="timezone"
                            value={selected ?? ''}
                        />

                        <Combobox
                            value={selected}
                            onChange={(value: string | null) =>
                                setSelected(value)
                            }
                            onClose={() => setQuery('')}
                        >
                            <div className="relative max-w-md">
                                <ComboboxInput
                                    aria-label="Timezone"
                                    placeholder="Not set, using your browser's timezone"
                                    displayValue={(name: string | null) => {
                                        if (name === null) {
                                            return '';
                                        }

                                        const timeZone = findTimeZone(name);

                                        return timeZone === undefined
                                            ? name
                                            : `${offset(timeZone)} ${timeZone.name}`;
                                    }}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    className="flex h-9 w-full min-w-0 rounded-md border border-input bg-transparent py-1 pr-9 pl-3 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                                    data-test="timezone-input"
                                />
                                <ComboboxButton className="absolute inset-y-0 right-0 flex items-center px-2.5 text-muted-foreground">
                                    <ChevronsUpDown className="size-4" />
                                </ComboboxButton>
                            </div>

                            <ComboboxOptions
                                anchor="bottom start"
                                className="z-50 max-h-72 w-(--input-width) overflow-y-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md [--anchor-gap:4px] empty:invisible"
                            >
                                {filtered.length === 0 && (
                                    <p className="px-2 py-1.5 text-sm text-muted-foreground">
                                        No timezone matches "{query.trim()}".
                                    </p>
                                )}
                                {filtered.map((timeZone) => (
                                    <ComboboxOption
                                        key={timeZone.name}
                                        value={timeZone.name}
                                        className="group flex cursor-default items-start gap-2 rounded-sm px-2 py-1.5 text-sm select-none data-focus:bg-accent data-focus:text-accent-foreground"
                                    >
                                        <Check className="mt-0.5 size-4 shrink-0 opacity-0 group-data-selected:opacity-100" />
                                        <span className="min-w-0">
                                            <span className="block truncate">
                                                <span className="font-mono text-muted-foreground">
                                                    {offset(timeZone)}
                                                </span>{' '}
                                                {timeZone.name}
                                            </span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {describe(timeZone)}
                                            </span>
                                        </span>
                                    </ComboboxOption>
                                ))}
                            </ComboboxOptions>
                        </Combobox>

                        <InputError
                            message={errors.timezone}
                            className="mt-2"
                        />

                        <div className="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-sm">
                            {detected !== undefined &&
                                detected.name !== selected && (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setSelected(detected.name)
                                        }
                                        className="text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                                    >
                                        Use {detected.name} from this browser
                                    </button>
                                )}
                            {selected !== null && (
                                <button
                                    type="button"
                                    onClick={() => setSelected(null)}
                                    className="text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                                >
                                    Unset
                                </button>
                            )}
                        </div>

                        <Button
                            type="submit"
                            className="mt-5"
                            disabled={processing || selected === user.timezone}
                            data-test="save-timezone-button"
                        >
                            Save timezone
                        </Button>
                    </>
                )}
            </Form>
        </section>
    );
}
