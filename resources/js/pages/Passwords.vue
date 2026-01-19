<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useDebounceFn, useIntersectionObserver } from '@vueuse/core';
import { Copy, Eye, EyeOff, Plus, RefreshCw, Trash2, Lock, Key } from 'lucide-vue-next';
import { 
    importKeyFromBase64,
    decryptClientSide, 
    encryptClientSide,
    deriveKeyFromPIN,
    unwrapMasterKey,
    exportKeyToBase64
} from '@/lib/crypto';
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
import axios from 'axios';

interface Password {
    id: number;
    domain: string;
    username: string;
    password: string;
    icon_path: string | null;
    created_at: string;
}

const props = defineProps<{
    passwords: Password[];
    next_cursor: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Passwords', href: '/passwords' },
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

// PIN modal state
const showPinModal = ref(false);
const pin = ref('');
const pinError = ref('');
const pendingAction = ref<{ type: 'view' | 'copy' | 'add', passwordId?: number, data?: any, item?: any } | null>(null);
const encryptionData = ref<{encrypted_master_key: string; pin_salt: string} | null>(null);

// Master Key cache with timeout (1 minute = 60000ms)
const PIN_CACHE_DURATION = 60000;
const cachedMasterKey = ref<{ key: CryptoKey, expiresAt: number } | null>(null);

// Check if cached key is still valid
const getCachedMasterKey = (): CryptoKey | null => {
    if (cachedMasterKey.value && Date.now() < cachedMasterKey.value.expiresAt) {
        return cachedMasterKey.value.key;
    }
    cachedMasterKey.value = null;
    return null;
};

// Store master key in cache
const cacheMasterKey = (key: CryptoKey) => {
    cachedMasterKey.value = {
        key,
        expiresAt: Date.now() + PIN_CACHE_DURATION
    };
};

// Decrypted cache (cleared after each action)
const decryptedCache = ref<Map<number, {username: string, password: string}>>(new Map());

// Load encryption data on mount
const loadEncryptionData = async () => {
    try {
        const res = await axios.get('/api/vault/encryption-data');
        if (res.data.hasEncryption) {
            encryptionData.value = {
                encrypted_master_key: res.data.encrypted_master_key,
                pin_salt: res.data.pin_salt,
            };
        }
    } catch (e) {
        console.error('Failed to get encryption data:', e);
    }
};
loadEncryptionData();

// Request PIN for an action - check cache first, only show modal if needed
const requestPin = async (action: 'view' | 'copy' | 'add', passwordId?: number, data?: any) => {
    // Store the password item now
    let item = null;
    if (passwordId) {
        item = props.passwords.find(p => p.id === passwordId);
        if (!item) {
            alert('Password não encontrada!');
            return;
        }
    }

    // Check if we have a valid cached key
    const cachedKey = getCachedMasterKey();
    if (cachedKey) {
        // Execute directly without showing PIN modal
        try {
            switch (action) {
                case 'view':
                    await handleViewPassword(item!, cachedKey);
                    break;
                case 'copy':
                    await handleCopyPassword(item!, cachedKey);
                    break;
                case 'add':
                    await handleAddPassword(cachedKey);
                    break;
            }
            return;
        } catch (e) {
            console.error('Cached key failed:', e);
            // Cache might be invalid, clear it and show modal
            cachedMasterKey.value = null;
        }
    }

    // No valid cache - show PIN modal
    pendingAction.value = { type: action, passwordId, data, item };
    pin.value = '';
    pinError.value = '';
    showPinModal.value = true;
};

// Execute action after PIN verification
const executeWithPin = async () => {
    if (!pin.value || !encryptionData.value) {
        pinError.value = 'Insere o PIN';
        return;
    }

    try {
        // Derive key from PIN
        const unwrappingKey = await deriveKeyFromPIN(pin.value, encryptionData.value.pin_salt);
        const masterKey = await unwrapMasterKey(encryptionData.value.encrypted_master_key, unwrappingKey);

        // Cache the key for 1 minute
        cacheMasterKey(masterKey);

        // Execute pending action using the stored item
        if (pendingAction.value) {
            switch (pendingAction.value.type) {
                case 'view':
                    await handleViewPassword(pendingAction.value.item!, masterKey);
                    break;
                case 'copy':
                    await handleCopyPassword(pendingAction.value.item!, masterKey);
                    break;
                case 'add':
                    await handleAddPassword(masterKey);
                    break;
            }
        }

        // Clear PIN and close modal
        showPinModal.value = false;
        pin.value = '';
        pendingAction.value = null;

    } catch (e) {
        console.error('PIN error:', e);
        pinError.value = 'PIN incorreto';
    }
};

// Handle view password - now receives item directly (stored before modal opened)
const handleViewPassword = async (item: Password, masterKey: CryptoKey) => {
    try {
        const decryptedPassword = await decryptClientSide(item.password, masterKey);
        const decryptedUser = await decryptClientSide(item.username, masterKey);
        
        decryptedCache.value.set(item.id, { username: decryptedUser, password: decryptedPassword });
        visiblePasswords.value.add(item.id);
    } catch (e) {
        console.error('Decryption error:', e);
        alert('Erro ao decifrar! A password pode ter sido encriptada com outra Master Key.');
    }
};

// Handle copy password - now receives item directly
const handleCopyPassword = async (item: Password, masterKey: CryptoKey) => {
    try {
        const decrypted = await decryptClientSide(item.password, masterKey);
        await navigator.clipboard.writeText(decrypted);
        alert('Password copiada!');
    } catch (e) {
        console.error('Decryption error:', e);
        alert('Erro ao decifrar! A password pode ter sido encriptada com outra Master Key.');
    }
};

// Handle add password
const handleAddPassword = async (masterKey: CryptoKey) => {
    const encryptedUsername = await encryptClientSide(form.username, masterKey);
    const encryptedPassword = await encryptClientSide(form.password, masterKey);

    const encryptedForm = useForm({
        domain: form.domain,
        username: encryptedUsername,
        password: encryptedPassword,
    });

    encryptedForm.post('/passwords', {
        onSuccess: () => {
            isAddModalOpen.value = false;
            form.reset();
            // Force full page reload to avoid duplicates
            router.visit('/passwords', { replace: true });
        },
    });
};

// Toggle password visibility (requires PIN)
const togglePasswordVisibility = (id: number) => {
    if (visiblePasswords.value.has(id)) {
        // Hide password (no PIN needed)
        visiblePasswords.value.delete(id);
        decryptedCache.value.delete(id);
    } else {
        // Show password (requires PIN)
        requestPin('view', id);
    }
};

// Copy password (requires PIN)
const copyPassword = (id: number) => {
    requestPin('copy', id);
};

// Add password (requires PIN)
const addPassword = () => {
    if (!form.domain || !form.username || !form.password) {
        return;
    }
    requestPin('add');
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

const handleSearch = useDebounceFn((value: string) => {
    router.get('/passwords', { search: value }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}, 300);

watch(search, (value) => handleSearch(value));

const landmark = ref(null);

useIntersectionObserver(landmark, ([{ isIntersecting }]) => {
    if (isIntersecting) loadMore();
}, { rootMargin: '500px' });

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
    if (confirm('Tens a certeza que queres eliminar?')) {
        router.delete(`/passwords/${id}`, {
            preserveScroll: true,
            preserveState: true,
        });
    }
};

// Get display value for password
const getDisplayedPassword = (item: Password) => {
    const cached = decryptedCache.value.get(item.id);
    return cached?.password || '••••••••';
};

const getDisplayedUsername = (item: Password) => {
    const cached = decryptedCache.value.get(item.id);
    return cached?.username || '••••••••';
};
</script>

<template>
    <Head title="Passwords" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <div class="mx-auto w-full max-w-4xl">
                <!-- Header -->
                <div class="mb-6 flex items-center gap-4">
                    <div class="relative flex-1">
                        <input
                            v-model="search"
                            type="search"
                            name="password-search"
                            autocomplete="off"
                            placeholder="Pesquisar..."
                            class="w-full rounded-full border border-gray-300 bg-gray-50 px-6 py-3 text-gray-900 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                        />
                    </div>

                    <Dialog v-model:open="isAddModalOpen">
                        <DialogTrigger as-child>
                            <Button class="rounded-full px-6 py-6">
                                <Plus class="mr-2 h-5 w-5" />
                                Adicionar
                            </Button>
                        </DialogTrigger>
                        <DialogContent class="sm:max-w-[425px]">
                            <DialogHeader>
                                <DialogTitle>Adicionar Password</DialogTitle>
                                <DialogDescription>
                                    Vais precisar de inserir o PIN para guardar.
                                </DialogDescription>
                            </DialogHeader>
                            <form @submit.prevent="addPassword" class="space-y-4">
                                <div class="space-y-2">
                                    <Label for="domain">Domínio / Site</Label>
                                    <Input id="domain" v-model="form.domain" placeholder="exemplo.com" required />
                                </div>
                                <div class="space-y-2">
                                    <Label for="username">Username / Email</Label>
                                    <Input id="username" v-model="form.username" placeholder="utilizador" required />
                                </div>
                                <div class="space-y-2">
                                    <Label for="password">Password</Label>
                                    <div class="flex gap-2">
                                        <Input id="password" v-model="form.password" type="text" required />
                                        <Button type="button" variant="outline" size="icon" @click="generatePassword">
                                            <RefreshCw class="h-4 w-4" />
                                        </Button>
                                    </div>
                                </div>

                                <!-- Generation Options -->
                                <div class="rounded-lg border p-3 space-y-3 bg-muted/50">
                                    <div class="text-sm font-medium">Opções de Geração</div>
                                    <div class="flex items-center justify-between">
                                        <Label for="length" class="text-xs">Tamanho: {{ passwordLength }}</Label>
                                        <input
                                            id="length"
                                            type="range"
                                            v-model.number="passwordLength"
                                            min="8"
                                            max="64"
                                            class="w-24 h-2 bg-gray-200 rounded-lg cursor-pointer dark:bg-gray-700"
                                        />
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <input id="symbols" type="checkbox" v-model="includeSymbols" class="h-4 w-4 rounded" />
                                        <Label for="symbols" class="text-xs font-normal">Incluir Símbolos</Label>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <input id="numbers" type="checkbox" v-model="includeNumbers" class="h-4 w-4 rounded" />
                                        <Label for="numbers" class="text-xs font-normal">Incluir Números</Label>
                                    </div>
                                </div>

                                <DialogFooter>
                                    <Button type="submit">
                                        <Lock class="h-4 w-4 mr-2" />
                                        Guardar (requer PIN)
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                <!-- Password List -->
                <div class="space-y-2">
                    <div
                        v-for="item in passwords"
                        :key="item.id"
                        class="flex items-center justify-between rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md dark:border-gray-700 dark:bg-gray-800"
                    >
                        <div class="flex items-center gap-4">
                            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-xl font-bold text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                {{ item.domain.charAt(0).toUpperCase() }}
                            </div>
                            <div>
                                <h3 class="font-medium text-gray-900 dark:text-white">{{ item.domain }}</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ visiblePasswords.has(item.id) ? getDisplayedUsername(item) : '••••••••' }}
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <div class="mr-4 hidden sm:block">
                                <span v-if="visiblePasswords.has(item.id)" class="font-mono text-gray-700 dark:text-gray-300">
                                    {{ getDisplayedPassword(item) }}
                                </span>
                                <span v-else class="text-gray-400">••••••••</span>
                            </div>

                            <button 
                                @click="togglePasswordVisibility(item.id)" 
                                class="rounded-full p-2 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700"
                                :title="visiblePasswords.has(item.id) ? 'Esconder' : 'Ver (requer PIN)'"
                            >
                                <EyeOff v-if="visiblePasswords.has(item.id)" class="h-5 w-5" />
                                <Eye v-else class="h-5 w-5" />
                            </button>

                            <button 
                                @click="copyPassword(item.id)" 
                                class="rounded-full p-2 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700"
                                title="Copiar (requer PIN)"
                            >
                                <Copy class="h-5 w-5" />
                            </button>

                            <button 
                                @click="deletePassword(item.id)" 
                                class="rounded-full p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20"
                            >
                                <Trash2 class="h-5 w-5" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Infinite Scroll -->
                <div v-if="next_cursor" ref="landmark" class="flex justify-center py-8">
                    <div class="h-6 w-6 animate-spin rounded-full border-2 border-gray-300 border-t-blue-600"></div>
                </div>

                <div v-else-if="passwords.length === 0" class="text-center py-12 text-gray-500">
                    Ainda não tens passwords guardadas.
                </div>
            </div>
        </div>

        <!-- PIN Modal -->
        <Dialog v-model:open="showPinModal">
            <DialogContent class="sm:max-w-[350px]">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <Lock class="h-5 w-5 text-indigo-600" />
                        Insere o teu PIN
                    </DialogTitle>
                    <DialogDescription>
                        {{ pendingAction?.type === 'view' ? 'Para ver a password' : 
                           pendingAction?.type === 'copy' ? 'Para copiar a password' : 
                           'Para guardar a password' }}
                    </DialogDescription>
                </DialogHeader>

                <form @submit.prevent="executeWithPin" class="py-4">
                    <input
                        v-model="pin"
                        type="password"
                        placeholder="PIN"
                        maxlength="20"
                        autocomplete="off"
                        name="pin-verification"
                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                    />
                    <p v-if="pinError" class="text-sm text-red-500 mt-2">{{ pinError }}</p>
                </form>

                <DialogFooter>
                    <Button variant="outline" @click="showPinModal = false">Cancelar</Button>
                    <Button @click="executeWithPin">
                        <Key class="h-4 w-4 mr-2" />
                        Confirmar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
