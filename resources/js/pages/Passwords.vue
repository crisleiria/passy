<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, WhenVisible } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { Copy, Eye, EyeOff } from 'lucide-vue-next';

interface Password {
    id: number;
    domain: string;
    username: string;
    password: string; // In a real app, this would be encrypted/decrypted
    icon_path: string | null;
    created_at: string;
}

const props = defineProps<{
    passwords: {
        data: Password[];
        next_cursor: string | null;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Passwords',
        href: '/passwords',
    },
];

const search = ref('');
const visiblePasswords = ref<Set<number>>(new Set());

const togglePasswordVisibility = (id: number) => {
    if (visiblePasswords.value.has(id)) {
        visiblePasswords.value.delete(id);
    } else {
        visiblePasswords.value.add(id);
    }
};

const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
    // Optional: Show toast notification
};

const handleSearch = useDebounceFn((value: string) => {
    router.get(
        '/passwords',
        { search: value },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        }
    );
}, 300);

watch(search, (value) => {
    handleSearch(value);
});

const loadMore = () => {
    if (props.passwords.next_cursor) {
        router.reload({
            data: { cursor: props.passwords.next_cursor },
            only: ['passwords'],
            preserveScroll: true,
            preserveState: true,
        });
    }
};
</script>

<template>
    <Head title="Passwords" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <div class="mx-auto w-full max-w-4xl">
                <!-- Search Bar -->
                <div class="mb-6">
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search passwords..."
                        class="w-full rounded-full border border-gray-300 bg-gray-50 px-6 py-3 text-gray-900 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400 dark:focus:border-blue-500 dark:focus:ring-blue-500"
                    />
                </div>

                <!-- Password List -->
                <div class="space-y-2">
                    <div
                        v-for="item in passwords.data"
                        :key="item.id"
                        class="flex items-center justify-between rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md dark:border-gray-700 dark:bg-gray-800"
                    >
                        <div class="flex items-center gap-4">
                            <!-- Icon -->
                            <div
                                class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-xl font-bold text-gray-600 dark:bg-gray-700 dark:text-gray-300"
                            >
                                <img
                                    v-if="item.icon_path"
                                    :src="item.icon_path"
                                    alt=""
                                    class="h-8 w-8 rounded-full"
                                    @error="item.icon_path = null"
                                />
                                <span v-else>{{ item.domain.charAt(0).toUpperCase() }}</span>
                            </div>

                            <!-- Domain & Username -->
                            <div>
                                <h3 class="font-medium text-gray-900 dark:text-white">
                                    {{ item.domain }}
                                </h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ item.username }}
                                </p>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center gap-2">
                            <!-- Password Display -->
                            <div class="mr-4 hidden sm:block">
                                <span
                                    v-if="visiblePasswords.has(item.id)"
                                    class="font-mono text-gray-700 dark:text-gray-300"
                                >
                                    {{ item.password }}
                                </span>
                                <span v-else class="text-gray-400">••••••••</span>
                            </div>

                            <button
                                @click="togglePasswordVisibility(item.id)"
                                class="rounded-full p-2 text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700"
                                title="Toggle Visibility"
                            >
                                <EyeOff v-if="visiblePasswords.has(item.id)" class="h-5 w-5" />
                                <Eye v-else class="h-5 w-5" />
                            </button>

                            <button
                                @click="copyToClipboard(item.password)"
                                class="rounded-full p-2 text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700"
                                title="Copy Password"
                            >
                                <Copy class="h-5 w-5" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Infinite Scroll Trigger -->
                <WhenVisible
                    v-if="passwords.next_cursor"
                    :always="true"
                    :buffer="500"
                    @visible="loadMore"
                >
                    <div class="flex justify-center py-8">
                        <div
                            class="h-6 w-6 animate-spin rounded-full border-2 border-gray-300 border-t-blue-600"
                        ></div>
                    </div>
                </WhenVisible>

                 <div v-else-if="passwords.data.length === 0" class="text-center py-12 text-gray-500">
                    No passwords found.
                </div>
            </div>
        </div>
    </AppLayout>
</template>
