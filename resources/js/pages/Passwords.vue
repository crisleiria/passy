<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useDebounceFn, useIntersectionObserver } from '@vueuse/core';
import { Copy, Eye, EyeOff, Plus, RefreshCw, Trash2 } from 'lucide-vue-next';
import { deriveKey, decryptClientSide, encryptClientSide } from '@/lib/crypto';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import InputError from '@/components/InputError.vue';
import Label from '@/components/ui/label/Label.vue';
import Input from '@/components/ui/input/Input.vue';
import Button from '@/components/ui/button/Button.vue';

interface Password {
    id: number;
    domain: string;
    username: string;
    password: string; // In a real app, this would be encrypted/decrypted
    icon_path: string | null;
    created_at: string;
}

const props = defineProps<{
    passwords: Password[];
    next_cursor: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Passwords',
        href: '/passwords',
    },
];

const search = ref('');
const visiblePasswords = ref<Set<number>>(new Set());
const isAddModalOpen = ref(false);

const form = useForm({
    domain: '',
    username: '',
    password: '',
});

const passwordLength = ref(20);
const includeSymbols = ref(true);
const includeNumbers = ref(true);

// Variáveis de Estado (Reativas)
const masterPassword = ref('');
const isVaultUnlocked = ref(false); // Controla se mostramos a lista ou o input de bloqueio
const cryptoKey = ref(null);        // Guarda a chave derivada em memória (nunca na BD/Storage)
const decryptedPasswords = ref([]); // Lista local para visualização

// 1. Função para desbloquear o cofre
const unlockVault = async () => {
    if (!masterPassword.value) return;

    try {
        // Gera a chave a partir do que o utilizador escreveu
        cryptoKey.value = await deriveKey(masterPassword.value);

        // Percorre todas as passwords que vieram do servidor e tenta desencriptar
        const promises = props.passwords.map(async (p) => {
            return {
                ...p,
                // Mantemos o domínio visível (texto limpo), mas revelamos user/pass
                username: await decryptClientSide(p.username, cryptoKey.value),
                password: await decryptClientSide(p.password, cryptoKey.value),
            };
        });

        // Espera que todas sejam desencriptadas
        decryptedPasswords.value = await Promise.all(promises);

        // Sucesso! Mostra a lista.
        isVaultUnlocked.value = true;
    } catch (error) {
        console.error(error);
        alert("Erro ao processar chaves. Verifique a consola.");
    }
};

const generatePassword = () => {
    const charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    const numbers = '0123456789';
    const symbols = '!@#$%^&*()_+~`|}{[]:;?><,./-=';

    let chars = charset;
    if (includeNumbers.value) chars += numbers;
    if (includeSymbols.value) chars += symbols;

    let password = '';
    for (let i = 0; i < passwordLength.value; i++) {
        password += chars.charAt(Math.floor(Math.random() * chars.length));
    }

    form.password = password;
};

import { store } from '@/routes/passwords';

// const submit = () => {
//     form.post(store(), {
//         onSuccess: () => {
//             isAddModalOpen.value = false;
//             form.reset();
//         },
//     });
// };

const createPassword = async () => {
    if (!cryptoKey.value) {
        alert("O cofre precisa de estar desbloqueado para guardar dados!");
        return;
    }

    // Prepara os dados encriptados para enviar ao Laravel
    const encryptedUsername = await encryptClientSide(form.username, cryptoKey.value);
    const encryptedPassword = await encryptClientSide(form.password, cryptoKey.value);

    // Usa um form temporário ou envia manualmente
    const encryptedForm = useForm({
        domain: form.domain, // Domínio vai em texto limpo (para pesquisa)
        username: encryptedUsername,
        password: encryptedPassword,
    });

    encryptedForm.post(store(), {
        onSuccess: () => {
            isAddModalOpen.value = false;
            form.reset();
        },
    });
};

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

const landmark = ref(null);

useIntersectionObserver(
    landmark,
    ([{ isIntersecting }]) => {
        if (isIntersecting) {
            loadMore();
        }
    },
    {
        rootMargin: '500px',
    }
);

const loadMore = () => {
    if (props.next_cursor) {
        router.reload({
            data: { cursor: props.next_cursor },
            only: ['passwords', 'next_cursor'],
            preserveScroll: true,
            preserveState: true,
        });
    }
};

const deletePassword = (id: number) => {
    if (confirm('Are you sure you want to delete this password?')) {
        router.delete(`/passwords/${id}`, {
            preserveScroll: true,
            preserveState: true,
        });
    }
};
</script>

<template>
    <Head title="Passwords" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div v-if="!isVaultUnlocked" class="flex flex-col items-center justify-center py-10 space-y-4">
            <h2 class="text-2xl font-bold text-gray-800">🔐 Cofre Bloqueado</h2>
            <p class="text-gray-500">
                Os seus dados estão encriptados. Insira a sua <span class="font-bold">Master Password</span>
                (a mesma que definiu no seu cérebro, não a Passkey) para desencriptar localmente.
            </p>

            <div class="flex gap-2">
                <input
                    v-model="masterPassword"
                    type="password"
                    class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                    placeholder="Master Password..."
                    @keyup.enter="unlockVault"
                >
                <button
                    @click="unlockVault"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded"
                >
                    Desbloquear
                </button>
            </div>
        </div>
        <div v-else class="flex h-full flex-1 flex-col gap-4 p-4">
            <div class="mx-auto w-full max-w-4xl">
                <!-- Search Bar and Add Button -->
                <div class="mb-6 flex items-center gap-4">
                    <div class="relative flex-1">
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Search passwords..."
                            class="w-full rounded-full border border-gray-300 bg-gray-50 px-6 py-3 text-gray-900 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400 dark:focus:border-blue-500 dark:focus:ring-blue-500"
                        />
                    </div>

                    <Dialog v-model:open="isAddModalOpen">
                        <DialogTrigger as-child>
                            <Button class="rounded-full px-6 py-6">
                                <Plus class="mr-2 h-5 w-5" />
                                Add
                            </Button>
                        </DialogTrigger>
                        <DialogContent class="sm:max-w-[425px]">
                            <DialogHeader>
                                <DialogTitle>Add Password</DialogTitle>
                                <DialogDescription>
                                    Add a new password to your vault.
                                </DialogDescription>
                            </DialogHeader>
                            <form @submit.prevent="createPassword" class="space-y-4">
                                <div class="space-y-2">
                                    <Label for="domain">Domain</Label>
                                    <Input
                                        id="domain"
                                        v-model="form.domain"
                                        placeholder="example.com"
                                        required
                                    />
                                    <InputError :message="form.errors.domain" />
                                </div>
                                <div class="space-y-2">
                                    <Label for="username">Username</Label>
                                    <Input
                                        id="username"
                                        v-model="form.username"
                                        placeholder="johndoe"
                                        required
                                    />
                                    <InputError :message="form.errors.username" />
                                </div>
                                <div class="space-y-2">
                                    <Label for="password">Password</Label>
                                    <div class="flex gap-2">
                                        <Input
                                            id="password"
                                            v-model="form.password"
                                            type="text"
                                            required
                                        />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            @click="generatePassword"
                                            title="Generate Password"
                                        >
                                            <RefreshCw class="h-4 w-4" />
                                        </Button>
                                    </div>
                                    <InputError :message="form.errors.password" />
                                </div>

                                <!-- Password Generation Options -->
                                <div class="rounded-lg border p-3 space-y-3 bg-muted/50">
                                    <div class="text-sm font-medium">Generation Options</div>
                                    <div class="flex items-center justify-between">
                                        <Label for="length" class="text-xs">Length: {{ passwordLength }}</Label>
                                        <input
                                            id="length"
                                            type="range"
                                            v-model.number="passwordLength"
                                            min="8"
                                            max="64"
                                            class="w-24 h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer dark:bg-gray-700"
                                        />
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <input
                                            id="symbols"
                                            type="checkbox"
                                            v-model="includeSymbols"
                                            class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:ring-offset-gray-800"
                                        />
                                        <Label for="symbols" class="text-xs font-normal">Include Symbols</Label>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <input
                                            id="numbers"
                                            type="checkbox"
                                            v-model="includeNumbers"
                                            class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:ring-offset-gray-800"
                                        />
                                        <Label for="numbers" class="text-xs font-normal">Include Numbers</Label>
                                    </div>
                                </div>

                                <DialogFooter>
                                    <Button type="submit" :disabled="form.processing">
                                        Save Password
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                <!-- Password List -->
                <div class="space-y-2">
                    <div
                        v-for="item in decryptedPasswords"
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

                            <button
                                @click="deletePassword(item.id)"
                                class="rounded-full p-2 text-red-500 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20"
                                title="Delete Password"
                            >
                                <Trash2 class="h-5 w-5" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Infinite Scroll Trigger -->
                <div
                    v-if="next_cursor"
                    ref="landmark"
                    class="flex justify-center py-8"
                >
                    <div
                        class="h-6 w-6 animate-spin rounded-full border-2 border-gray-300 border-t-blue-600"
                    ></div>
                </div>

                 <div v-else-if="passwords.length === 0" class="text-center py-12 text-gray-500">
                    No passwords found.
                </div>
            </div>
        </div>
    </AppLayout>
</template>
