import http from '@/api/http';

export default (uuid: string, file: string, directory?: boolean): Promise<string> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${uuid}/files/download`, {
            params: directory ? { file, directory } : { file },
        })
            .then(({ data }) => resolve(data.attributes.url))
            .catch(reject);
    });
};
