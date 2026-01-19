<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { Head, router } from '@inertiajs/vue3';
import { LoaderCircle, Fingerprint, Copy, AlertTriangle } from 'lucide-vue-next';
import { ref } from 'vue';
import axios from 'axios';
import { startRegistration } from '@simplewebauthn/browser';
import {
    generateMasterKey,
    generateSalt,
    deriveKeyFromPIN,
    wrapMasterKey,
    exportKeyToBase64,
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

const name = ref('');
const email = ref('');
const pin = ref('');
const pinConfirm = ref('');
const error = ref('');
const processing = ref(false);
const showMasterKeyModal = ref(false);
const masterKeyDisplay = ref('');
const masterKeyCopied = ref(false);
const masterKeySaved = ref(false);

const registerWithWebAuthn = async () => {
    // Validation
    if (!name.value || !email.value) {
        error.value = 'Preenche nome e email';
        return;
    }
    if (pin.value.length < 4) {
        error.value = 'PIN deve ter pelo menos 4 caracteres';
        return;
    }
    if (pin.value !== pinConfirm.value) {
        error.value = 'Os PINs não coincidem';
        return;
    }

    error.value = '';
    processing.value = true;

    try {
        // 1. Generate Master Key
        const masterKey = await generateMasterKey();
        const masterKeyBase64 = await exportKeyToBase64(masterKey);
        masterKeyDisplay.value = masterKeyBase64;

        // 2. Generate salt and derive wrapping key from PIN
        const salt = generateSalt();
        const saltBase64 = arrayBufferToBase64(salt);
        const wrappingKey = await deriveKeyFromPIN(pin.value, salt);

        // 3. Wrap (encrypt) the Master Key with PIN-derived key
        const wrappedMasterKey = await wrapMasterKey(masterKey, wrappingKey);

        // 4. Get WebAuthn registration options
        const optionsRes = await axios.post('/auth/webauthn/register-options', {
            name: name.value,
            email: email.value,
        });

        // 5. Start WebAuthn registration (Windows Hello)
        const regResponse = await startRegistration({ optionsJSON: optionsRes.data });

        // 6. Complete registration on server
        await axios.post('/auth/webauthn/register', {
            credential: JSON.stringify(regResponse),
            encrypted_master_key: wrappedMasterKey,
            pin_salt: saltBase64,
        });

        // 7. Show Master Key modal (no sessionStorage - will ask PIN for each action)
        processing.value = false;
        showMasterKeyModal.value = true;

    } catch (err: any) {
        console.error('Registration error:', err);
        error.value = err.response?.data?.error || err.message || 'Erro no registo';
        processing.value = false;
    }
};

const copyMasterKey = async () => {
    await navigator.clipboard.writeText(masterKeyDisplay.value);
    masterKeyCopied.value = true;
    setTimeout(() => masterKeyCopied.value = false, 2000);
};

const continueToVault = () => {
    showMasterKeyModal.value = false;
    router.get('/passwords');
};
</script>

<template>
    <AuthBase
        title="Criar Conta"
        description="Regista-te com PassKey e define um PIN"
    >
        <Head title="Registar" />

        <div class="flex flex-col gap-6">
            <!-- Name -->
            <div class="grid gap-2">
                <Label for="name">Nome</Label>
                <Input
                    id="name"
                    v-model="name"
                    type="text"
                    required
                    autofocus
                    autocomplete="name"
                    placeholder="O teu nome"
                />
            </div>

            <!-- Email -->
            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    v-model="email"
                    type="email"
                    required
                    autocomplete="email"
                    placeholder="email@exemplo.com"
                />
            </div>

            <!-- PIN -->
            <div class="grid gap-2">
                <Label for="pin">PIN de Desbloqueio</Label>
                <Input
                    id="pin"
                    v-model="pin"
                    type="password"
                    required
                    placeholder="Mínimo 4 caracteres"
                    minlength="4"
                    maxlength="20"
                />
                <p class="text-xs text-muted-foreground">
                    Este PIN será usado para desbloquear as tuas passwords
                </p>
            </div>

            <!-- Confirm PIN -->
            <div class="grid gap-2">
                <Label for="pinConfirm">Confirmar PIN</Label>
                <Input
                    id="pinConfirm"
                    v-model="pinConfirm"
                    type="password"
                    required
                    placeholder="Repete o PIN"
                    minlength="4"
                    maxlength="20"
                />
            </div>

            <!-- Info box -->
            <div class="rounded-lg border border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950 p-4">
                <p class="text-sm text-blue-800 dark:text-blue-200">
                    <strong>ℹ️ Importante:</strong> Vai ser criada uma <strong>Master Key</strong> de backup. 
                    Guarda-a num local seguro!
                </p>
            </div>

            <!-- Error -->
            <InputError v-if="error" :message="error" />

            <!-- Register button -->
            <Button
                type="button"
                class="w-full"
                :disabled="processing"
                @click="registerWithWebAuthn"
            >
                <LoaderCircle v-if="processing" class="h-4 w-4 animate-spin mr-2" />
                <Fingerprint v-else class="h-4 w-4 mr-2" />
                Criar Conta com PassKey
            </Button>

            <!-- Login link -->
            <div class="text-center text-sm text-muted-foreground">
                Já tens conta?
                <TextLink :href="login()">Entrar</TextLink>
            </div>
        </div>

        <!-- Master Key Modal -->
        <Dialog v-model:open="showMasterKeyModal">
            <DialogContent class="sm:max-w-[500px]" :closeable="false">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <AlertTriangle class="h-5 w-5 text-yellow-500" />
                        A Tua Master Key
                    </DialogTitle>
                    <DialogDescription class="text-yellow-600 dark:text-yellow-400">
                        ⚠️ Esta é a ÚNICA vez que vais ver esta chave. Se perderes o PIN, 
                        precisas desta chave para recuperar o acesso!
                    </DialogDescription>
                </DialogHeader>

                <div class="py-4 space-y-4">
                    <!-- Master Key display -->
                    <div class="rounded-lg bg-gray-100 dark:bg-gray-800 p-3 flex items-start gap-2">
                        <code class="flex-1 text-xs break-all font-mono">
                            {{ masterKeyDisplay }}
                        </code>
                        <Button
                            variant="ghost"
                            size="icon"
                            @click="copyMasterKey"
                            :title="masterKeyCopied ? 'Copiado!' : 'Copiar'"
                        >
                            <Copy class="h-4 w-4" />
                        </Button>
                    </div>

                    <p v-if="masterKeyCopied" class="text-sm text-green-600 text-center">
                        ✓ Copiado!
                    </p>

                    <!-- Confirmation checkbox -->
                    <div class="flex items-center gap-2">
                        <input
                            id="confirmSaved"
                            v-model="masterKeySaved"
                            type="checkbox"
                            class="h-4 w-4 rounded border-gray-300"
                        />
                        <label for="confirmSaved" class="text-sm">
                            Confirmo que guardei a Master Key em segurança
                        </label>
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        @click="continueToVault"
                        :disabled="!masterKeySaved"
                        class="w-full"
                    >
                        Continuar para o Vault
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AuthBase>
</template>
