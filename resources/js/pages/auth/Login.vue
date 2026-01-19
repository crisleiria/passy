<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { register } from '@/routes';
import { Head, router } from '@inertiajs/vue3';
import { LoaderCircle, Fingerprint, Key, RefreshCw } from 'lucide-vue-next';
import { ref } from 'vue';
import axios from 'axios';
import { startAuthentication } from '@simplewebauthn/browser';
import { 
    deriveKeyFromPIN, 
    unwrapMasterKey, 
    wrapMasterKey,
    importKeyFromBase64,
    generateSalt,
    arrayBufferToBase64
} from '@/lib/crypto';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

defineProps<{
    status?: string;
    canResetPassword?: boolean;
}>();

const email = ref('');
const masterKeyInput = ref('');
const error = ref('');
const processing = ref(false);
const showMasterKeyInput = ref(false);

// Reset PIN modal state
const showResetPinModal = ref(false);
const resetMasterKey = ref('');
const resetNewPin = ref('');
const resetNewPinConfirm = ref('');
const resetError = ref('');
const resetProcessing = ref(false);

// Login with Windows Hello (no PIN required)
const loginWithWebAuthn = async () => {
    if (!email.value) {
        error.value = 'Por favor insere o email';
        return;
    }

    error.value = '';
    processing.value = true;

    try {
        const optionsRes = await axios.post('/auth/webauthn/login-options', {
            email: email.value,
        });

        const authResponse = await startAuthentication({ optionsJSON: optionsRes.data });

        const loginRes = await axios.post('/auth/webauthn/login', {
            credential: JSON.stringify(authResponse),
            email: email.value,
        });

        // Redirect to passwords without asking for PIN
        // PIN will be requested when user tries to view/copy/add passwords
        router.get('/passwords');

    } catch (err: any) {
        console.error('Login error:', err);
        error.value = err.response?.data?.error || err.message || 'Erro no login';
        processing.value = false;
    }
};

// Login with Master Key (backup)
const loginWithMasterKey = async () => {
    if (!email.value || !masterKeyInput.value) {
        error.value = 'Por favor insere email e Master Key';
        return;
    }

    error.value = '';
    processing.value = true;

    try {
        await importKeyFromBase64(masterKeyInput.value.trim());
        await axios.post('/auth/webauthn/login-master-key', {
            email: email.value,
        });
        router.get('/passwords');

    } catch (err: any) {
        console.error('Master key login error:', err);
        error.value = err.response?.data?.error || 'Master Key inválida';
        processing.value = false;
    }
};

// Reset PIN with Master Key
const openResetPinModal = () => {
    resetMasterKey.value = '';
    resetNewPin.value = '';
    resetNewPinConfirm.value = '';
    resetError.value = '';
    showResetPinModal.value = true;
};

const resetPinWithMasterKey = async () => {
    if (!email.value) {
        resetError.value = 'Por favor insere o email primeiro';
        return;
    }
    if (!resetMasterKey.value) {
        resetError.value = 'Por favor insere a Master Key';
        return;
    }
    if (resetNewPin.value.length < 4) {
        resetError.value = 'O novo PIN deve ter pelo menos 4 caracteres';
        return;
    }
    if (resetNewPin.value !== resetNewPinConfirm.value) {
        resetError.value = 'Os PINs não coincidem';
        return;
    }

    resetError.value = '';
    resetProcessing.value = true;

    try {
        // 1. Import the Master Key to verify it's valid
        const masterKey = await importKeyFromBase64(resetMasterKey.value.trim());

        // 2. Generate new salt and derive new wrapping key from new PIN
        const newSalt = generateSalt();
        const newSaltBase64 = arrayBufferToBase64(newSalt);
        const newWrappingKey = await deriveKeyFromPIN(resetNewPin.value, newSalt);

        // 3. Wrap the Master Key with the new PIN-derived key
        const newWrappedMasterKey = await wrapMasterKey(masterKey, newWrappingKey);

        // 4. Send to server
        await axios.post('/auth/webauthn/reset-pin', {
            email: email.value,
            encrypted_master_key: newWrappedMasterKey,
            pin_salt: newSaltBase64,
        });

        // 5. Success!
        alert('PIN alterado com sucesso! Podes agora fazer login com o novo PIN.');
        showResetPinModal.value = false;
        resetProcessing.value = false;

    } catch (err: any) {
        console.error('Reset PIN error:', err);
        resetError.value = err.response?.data?.error || 'Master Key inválida ou erro no reset';
        resetProcessing.value = false;
    }
};

const toggleMasterKeyInput = () => {
    showMasterKeyInput.value = !showMasterKeyInput.value;
};
</script>

<template>
    <AuthBase
        title="Entrar no Passy"
        description="Usa PassKey para aceder ao teu cofre"
    >
        <Head title="Login" />

        <div v-if="status" class="mb-4 text-center text-sm font-medium text-green-600">
            {{ status }}
        </div>

        <div class="flex flex-col gap-6">
            <!-- Email -->
            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    v-model="email"
                    type="email"
                    required
                    autofocus
                    autocomplete="email"
                    placeholder="email@exemplo.com"
                    :disabled="showPinInput"
                />
            </div>

            <!-- Error message -->
            <InputError v-if="error" :message="error" />

            <!-- Windows Hello button -->
            <Button
                v-if="!showPinInput && !showMasterKeyInput"
                type="button"
                class="w-full"
                :disabled="processing"
                @click="loginWithWebAuthn"
            >
                <LoaderCircle v-if="processing" class="h-4 w-4 animate-spin mr-2" />
                <Fingerprint v-else class="h-4 w-4 mr-2" />
                Entrar com PassKey
            </Button>

            <!-- Divider -->
            <div v-if="!showMasterKeyInput" class="relative">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-gray-300 dark:border-gray-600"></div>
                </div>
                <div class="relative flex justify-center text-sm">
                    <span class="bg-background px-2 text-muted-foreground">ou</span>
                </div>
            </div>

            <!-- Master Key button -->
            <Button
                v-if="!showMasterKeyInput"
                type="button"
                variant="outline"
                class="w-full"
                @click="toggleMasterKeyInput"
            >
                <Key class="h-4 w-4 mr-2" />
                Usar Master Key
            </Button>

            <!-- Master Key input -->
            <div v-if="showMasterKeyInput" class="space-y-4">
                <div class="grid gap-2">
                    <Label for="masterKey">Master Key</Label>
                    <textarea
                        id="masterKey"
                        v-model="masterKeyInput"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        rows="2"
                        placeholder="Cola a tua Master Key"
                    ></textarea>
                </div>
                <Button
                    type="button"
                    class="w-full"
                    :disabled="processing"
                    @click="loginWithMasterKey"
                >
                    <LoaderCircle v-if="processing" class="h-4 w-4 animate-spin mr-2" />
                    Desbloquear
                </Button>
            </div>

            <!-- Register link -->
            <div class="text-center text-sm text-muted-foreground">
                Ainda não tens conta?
                <TextLink :href="register()">Criar conta</TextLink>
            </div>
        </div>

        <!-- Reset PIN Modal -->
        <Dialog v-model:open="showResetPinModal">
            <DialogContent class="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <RefreshCw class="h-5 w-5" />
                        Redefinir PIN
                    </DialogTitle>
                    <DialogDescription>
                        Usa a tua Master Key para definir um novo PIN.
                    </DialogDescription>
                </DialogHeader>

                <form @submit.prevent="resetPinWithMasterKey" class="space-y-4 py-4">
                    <div class="space-y-2">
                        <Label for="resetMasterKey">Master Key</Label>
                        <textarea
                            id="resetMasterKey"
                            v-model="resetMasterKey"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            rows="2"
                            placeholder="Cola a tua Master Key de backup"
                        ></textarea>
                    </div>

                    <div class="space-y-2">
                        <Label for="resetNewPin">Novo PIN (mínimo 4 caracteres)</Label>
                        <Input
                            id="resetNewPin"
                            v-model="resetNewPin"
                            type="password"
                            placeholder="••••"
                            maxlength="20"
                        />
                    </div>

                    <div class="space-y-2">
                        <Label for="resetNewPinConfirm">Confirmar novo PIN</Label>
                        <Input
                            id="resetNewPinConfirm"
                            v-model="resetNewPinConfirm"
                            type="password"
                            placeholder="••••"
                            maxlength="20"
                        />
                    </div>

                    <InputError v-if="resetError" :message="resetError" />
                </form>

                <DialogFooter>
                    <Button variant="outline" @click="showResetPinModal = false">
                        Cancelar
                    </Button>
                    <Button @click="resetPinWithMasterKey" :disabled="resetProcessing">
                        <LoaderCircle v-if="resetProcessing" class="h-4 w-4 animate-spin mr-2" />
                        Redefinir PIN
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AuthBase>
</template>
